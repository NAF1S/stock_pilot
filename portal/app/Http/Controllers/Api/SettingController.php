<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidationValidator;

class SettingController extends Controller
{
    /**
     * The only actions the status map understands. InventoryEngine evaluates
     * confirm, release and return before reserve when a legacy map duplicates
     * a status; new maps are validated to keep the lists disjoint.
     */
    private const STATUS_ACTIONS = ['reserve', 'confirm', 'release', 'return'];

    /** Statuses shipped by WooCommerce core. */
    private const WOOCOMMERCE_CORE_STATUSES = [
        'pending',
        'processing',
        'on-hold',
        'completed',
        'cancelled',
        'refunded',
        'failed',
        'trash',
        'draft',
        'auto-draft',
        'checkout-draft',
    ];

    /** Custom statuses this project supports (order-status plugins, shipments, returns). */
    private const CUSTOM_STATUSES = [
        'confirmed',
        'pending-payment',
        'shipped',
        'returned-charge',
        'returned-with-charge',
        'return-charged',
    ];

    /**
     * Statuses in InventoryEngine's built-in default map (and therefore in the
     * Settings UI defaults) that are not WooCommerce core. Without them a fresh
     * organisation — no orders, no webhook history, no saved map — could not
     * save the pre-filled form.
     */
    private const PROJECT_DEFAULT_STATUSES = ['returned'];

    public function __construct(private SettingService $settings)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;
        $org = Organization::find($orgId);

        return response()->json([
            'organization' => [
                'name' => $org?->name,
                'currency' => $org?->currency,
                'timezone' => $org?->timezone,
                'low_stock_email' => $org?->low_stock_email,
            ],
            'settings' => $this->settings->all($orgId),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;
        $org = Organization::findOrFail($orgId);

        /** @var array<string, list<string>>|null $statusMap Normalised map to persist. */
        $statusMap = null;

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'currency' => 'sometimes|string|size:3',
            'timezone' => 'sometimes|string|max:64',
            'low_stock_email' => 'sometimes|nullable|email',
            'allow_negative_stock' => 'sometimes|boolean',
            'warning_cooldown_hours' => 'sometimes|integer|min:0',
            'status_map' => 'sometimes|array',
            'status_map.*' => 'array',
            // Blank entries are allowed through (goal: drop them) — Laravel has
            // already turned '' / whitespace-only strings into null.
            'status_map.*.*' => 'nullable|string|max:64',
        ]);

        // A status map that references a status no order ever has — or that is
        // missing an action entirely — silently turns inventory work into a
        // no-op, so the semantics are checked here. Errors land in the default
        // 422 {"message": ..., "errors": {...}} payload the SPA renders.
        $validator->after(function (ValidationValidator $validator) use ($request, $orgId, &$statusMap): void {
            if ($validator->errors()->isNotEmpty()) {
                return; // basic rules already rejected the payload
            }

            $raw = $request->input('status_map');
            if (! is_array($raw)) {
                return; // not submitted (sometimes|array) or rejected above
            }

            $normalized = $this->normalizeStatusMap($raw);

            $this->validateStatusMap($validator, $normalized, $orgId);

            $statusMap = $this->knownActionsOnly($normalized);
        });

        $data = $validator->validate();

        if ($statusMap !== null) {
            $data['status_map'] = $statusMap;
        }

        $orgFields = array_intersect_key($data, array_flip(['name', 'currency', 'timezone', 'low_stock_email']));
        if (isset($orgFields['currency'])) {
            $orgFields['currency'] = strtoupper($orgFields['currency']);
        }
        if (! empty($orgFields)) {
            $org->fill($orgFields)->save();
        }

        foreach (['allow_negative_stock', 'warning_cooldown_hours', 'status_map'] as $key) {
            if (array_key_exists($key, $data)) {
                $this->settings->set($orgId, $key, $data[$key]);
            }
        }

        return response()->json([
            'ok' => true,
            'organization' => [
                'name' => $org->name,
                'currency' => $org->currency,
                'timezone' => $org->timezone,
                'low_stock_email' => $org->low_stock_email,
            ],
            'settings' => $this->settings->all($orgId),
        ]);
    }

    /** Send a test email to the configured low-stock address (synchronous). */
    public function testEmail(Request $request): JsonResponse
    {
        $orgId = $request->user()->organization_id;
        $org = Organization::findOrFail($orgId);
        $email = $org->low_stock_email;

        if (! $email) {
            return response()->json(['error' => 'No low-stock email is configured in Settings first.'], 422);
        }

        try {
            Mail::raw(
                "This is a test email from StockPilot.\n\nYour low-stock alerts will be sent to this address. If you received this, mail delivery is working.\n\n— StockPilot",
                function ($message) use ($email) {
                    $message->to($email)
                        ->subject('StockPilot test email — '.now()->format('Y-m-d H:i:s'))
                        ->from(config('mail.from.address'), config('mail.from.name'));
                }
            );
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Mail send failed: '.$e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'sent_to' => $email]);
    }

    /**
     * Lowercase + trim every status, drop blanks and duplicates, lowercase the
     * action keys. Unknown action keys are kept here so validation can name
     * them; only the four known actions are persisted (knownActionsOnly()).
     *
     * @param  array<array-key, mixed>  $map
     * @return array<string, list<string>>
     */
    private function normalizeStatusMap(array $map): array
    {
        $normalized = [];

        foreach ($map as $action => $statuses) {
            $action = strtolower(trim((string) $action));
            $list = [];

            foreach (is_array($statuses) ? $statuses : [] as $status) {
                if (! is_string($status)) {
                    continue; // rejected by the status_map.*.* rules
                }

                $status = strtolower(trim($status));

                if ($status !== '' && ! in_array($status, $list, true)) {
                    $list[] = $status;
                }
            }

            $normalized[$action] = $list;
        }

        return $normalized;
    }

    /**
     * Keep the four known actions only, in evaluation order.
     *
     * @param  array<string, list<string>>  $map
     * @return array<string, list<string>>
     */
    private function knownActionsOnly(array $map): array
    {
        $kept = [];

        foreach (self::STATUS_ACTIONS as $action) {
            if (array_key_exists($action, $map)) {
                $kept[$action] = $map[$action];
            }
        }

        return $kept;
    }

    /**
     * Semantic checks a plain rule set cannot express: action keys, empty
     * reserve/confirm lists, cross-list duplicates and unknown statuses.
     *
     * @param  array<string, list<string>>  $map  normalised map (action => statuses)
     */
    private function validateStatusMap(ValidationValidator $validator, array $map, int $organizationId): void
    {
        $unknownActions = array_values(array_diff(array_keys($map), self::STATUS_ACTIONS));

        if ($unknownActions !== []) {
            $validator->errors()->add('status_map', sprintf(
                'Unknown status_map action %s. Allowed actions are: %s.',
                $this->quotedList($unknownActions),
                implode(', ', self::STATUS_ACTIONS),
            ));
        }

        // InventoryEngine stops at the first match, so duplicates are ambiguous.
        $seen = [];       // status => action
        $duplicates = []; // human readable "status" (action + action)
        foreach (self::STATUS_ACTIONS as $action) {
            foreach ($map[$action] ?? [] as $status) {
                if (isset($seen[$status])) {
                    $duplicates[] = sprintf('"%s" (%s + %s)', $status, $seen[$status], $action);
                } else {
                    $seen[$status] = $action;
                }
            }
        }

        if ($duplicates !== []) {
            $validator->errors()->add('status_map', sprintf(
                'Each status may only appear in one action list; found duplicates: %s.',
                implode(', ', array_unique($duplicates)),
            ));
        }

        $known = $this->knownStatuses($organizationId);
        $unknownStatuses = [];
        foreach ($seen as $status => $action) {
            if (! in_array((string) $status, $known, true)) {
                $unknownStatuses[] = sprintf('"%s" (in %s)', $status, $action);
            }
        }

        if ($unknownStatuses !== []) {
            $validator->errors()->add('status_map', sprintf(
                'Unknown order status %s. Known statuses: %s.',
                implode(', ', $unknownStatuses),
                implode(', ', $known),
            ));
        }

        // An empty reserve/confirm list silently disables reserving/deducting.
        // Checked last so a typo'd status is the headline error in the SPA.
        foreach (['reserve', 'confirm'] as $required) {
            if (($map[$required] ?? []) === []) {
                $validator->errors()->add('status_map.'.$required, sprintf(
                    'The %s list cannot be empty — orders reaching it would be silently ignored. '
                    .'Add at least one status (e.g. %s).',
                    $required,
                    $required === 'reserve' ? 'pending, processing, on-hold' : 'completed',
                ));
            }
        }
    }

    /**
     * Every status the map may reference: WooCommerce core, the custom statuses
     * this project ships, plus anything this organisation has actually seen in
     * webhook events or orders (so plugin-specific statuses still pass).
     *
     * @return list<string>
     */
    private function knownStatuses(int $organizationId): array
    {
        $observed = DB::table('sp_webhook_events')
            ->where('organization_id', $organizationId)
            ->whereNotNull('wc_status')
            ->distinct()
            ->pluck('wc_status')
            ->merge(
                DB::table('sp_orders')
                    ->where('organization_id', $organizationId)
                    ->whereNotNull('status')
                    ->distinct()
                    ->pluck('status')
            );

        return collect([...self::WOOCOMMERCE_CORE_STATUSES, ...self::CUSTOM_STATUSES, ...self::PROJECT_DEFAULT_STATUSES])
            ->merge($observed)
            ->map(fn ($status) => strtolower(trim((string) $status)))
            ->filter(fn (string $status) => $status !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $values
     */
    private function quotedList(array $values): string
    {
        return implode(', ', array_map(fn (string $value) => '"'.$value.'"', $values));
    }
}
