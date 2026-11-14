import { useState } from 'react';
import { Link } from 'react-router-dom';
import { SectionHead } from '../../components/marketing';

/* -------------------------------------------------------------- the board */

type Runway = {
    item: string;
    sku: string;
    days: string;
    burn: string;
    state: string;
};

const RUNWAY: Runway[] = [
    { item: 'Thank-you card', sku: 'CARD-TY', days: '0', burn: '22/day', state: 'Out' },
    { item: 'Satin ribbon 20mm', sku: 'RIB-SAT-20', days: '2', burn: '14/day', state: 'Critical' },
    { item: 'Kraft box medium', sku: 'BOX-M-001', days: '5', burn: '9/day', state: 'Warning' },
    { item: 'Tissue paper sheet', sku: 'TIS-A4', days: '21', burn: '6/day', state: 'Healthy' },
];

function RunwayBoard() {
    return (
        <div className="board">
            <div className="board__head">
                <h2 className="board__title" id="runway">
                    Stockout runway
                </h2>
                <span className="board__live">
                    <span className="board__dot" aria-hidden="true" />
                    From the movement ledger
                </span>
            </div>
            <table aria-label="Days of stock left for four components, from the sales burn rate">
                <colgroup>
                    <col className="col-item" />
                    <col className="col-figure" />
                    <col className="col-burn" />
                    <col className="col-state" />
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col">Component</th>
                        <th scope="col">Days left</th>
                        <th scope="col">Burn</th>
                        <th scope="col">State</th>
                    </tr>
                </thead>
                <tbody>
                    {RUNWAY.map((r) => (
                        <tr key={r.sku}>
                            <td data-label="Component" className="board__cell--item">
                                <span className="board__item">{r.item}</span>
                            </td>
                            <td data-label="Days left">
                                <span
                                    className={r.days === '0' ? 'board__figure board__figure--out' : 'board__figure'}
                                >
                                    {r.days}
                                </span>
                            </td>
                            <td data-label="Burn" className="num">
                                {r.burn}
                            </td>
                            <td data-label="State" className="board__note">
                                {r.state}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
            <div className="board__foot">
                <span>Stock on hand divided by the daily burn over the last 30 days.</span>
                <span>Sample data</span>
            </div>
        </div>
    );
}

/* -------------------------------------------------------------------- hero */

function Hero() {
    return (
        <section className="wm-hero">
            <div className="container">
                <div className="wm-hero__grid">
                    <div className="wm-hero__head">
                        <h1>Inventory that knows what a kit is</h1>
                        <div className="wm-hero__copy">
                            <p className="lead">
                                Track every component, recipe and order for kits built on WooCommerce.
                            </p>
                            <div className="wm-hero__actions">
                                <Link to="/register" className="btn btn--accent">
                                    Start free
                                </Link>
                                <Link to="/login" className="btn btn--quiet">
                                    Sign in
                                </Link>
                            </div>
                        </div>
                        <dl className="wm-hero__facts">
                            <div>
                                <dt>Ledger</dt>
                                <dd>Append-only, no edits</dd>
                            </div>
                            <div>
                                <dt>Recipes</dt>
                                <dd>Nested up to ten levels</dd>
                            </div>
                            <div>
                                <dt>Bridge</dt>
                                <dd>Read-only, never writes back</dd>
                            </div>
                            <div>
                                <dt>Forecasting</dt>
                                <dd>Your own movements only</dd>
                            </div>
                        </dl>
                    </div>

                    <div className="wm-hero__board">
                        <RunwayBoard />
                    </div>
                </div>
            </div>
        </section>
    );
}

/* -------------------------------------------------------------- recipe tree */

const RECIPE = [
    { id: 'box', name: 'Kraft box medium', note: 'BOX-M-001', qty: '1', cost: '45.00' },
    { id: 'tissue', name: 'Tissue paper sheet', note: 'TIS-A4, 16.00 a sheet', qty: '2', cost: '32.00' },
    { id: 'ribbon', name: 'Satin ribbon 20mm', note: 'RIB-SAT-20, 22.50 a metre', qty: '1.5', cost: '33.75' },
    { id: 'soap', name: 'Handmade soap', note: 'Sub-product, has its own recipe', qty: '1', cost: '120.00', sub: true },
];

function Recipes() {
    return (
        <section id="features" className="sec sec--paper-3">
            <div className="container">
                <SectionHead
                    title="Every kit is a recipe"
                    line="A product that contains a product is still one deduction, level by level."
                    aside={
                        <p className="fine">
                            Signature Gift Set, as its recipe reads. Sub-products are expanded with cycle
                            detection.
                        </p>
                    }
                />

                <div className="recipes">
                    <div className="recipes__text">
                        <h3>What one order consumes</h3>
                        <p>Which materials and sub-products it takes, and how many per unit.</p>
                        <ul className="recipes__list">
                            <li>
                                <span>Nested assemblies</span>
                                <span>Ten levels</span>
                            </li>
                            <li>
                                <span>Material cost per unit</span>
                                <span>Live</span>
                            </li>
                            <li>
                                <span>Reprocess old orders</span>
                                <span>Safe</span>
                            </li>
                        </ul>
                    </div>

                    <div className="tree">
                        <div className="tree__head">
                            <p>Recipe: Signature Gift Set</p>
                            <span className="tree__state">Amounts in BDT</span>
                        </div>
                        <ul className="tree__rows">
                            {RECIPE.map((r) => (
                                <li key={r.id} className={r.sub ? 'tree__row tree__row--sub' : 'tree__row'}>
                                    <span className="tree__name">
                                        {r.name}
                                        <small>{r.note}</small>
                                    </span>
                                    <span className="tree__qty">×{r.qty}</span>
                                    <span className="tree__cost">{r.cost}</span>
                                </li>
                            ))}
                        </ul>
                        <div className="tree__total">
                            <span>Material cost per unit</span>
                            <strong>230.75</strong>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    );
}

/* ------------------------------------------------------------------ ledger */

const LEDGER = [
    {
        time: '11:40',
        what: 'Correction to order 1092',
        note: 'A new row that points at the original',
        delta: '+2',
        bal: '28',
        fix: true,
    },
    { time: '11:38', what: 'Order 1092', note: 'Signature Gift Set', delta: '−2', bal: '26' },
    { time: '09:14', what: 'Order 1091', note: 'Signature Gift Set', delta: '−14', bal: '28' },
    { time: '08:02', what: 'Delivery, supplier', note: 'Purchase order 88', delta: '+40', bal: '42' },
];

function Ledger() {
    return (
        <section className="sec sec--paper-2">
            <div className="container">
                <div className="ledger">
                    <div className="ledger__intro">
                        <h2>Rows are never edited</h2>
                        <p>
                            Every movement is recorded permanently. A mistake is corrected by a new row that
                            points at the one it fixes, so a balance can always be rebuilt from the history.
                        </p>
                        <dl className="ledger__value">
                            <dt>Rows ever edited</dt>
                            <dd>0</dd>
                            <p>A correction is a new row that points at the one it fixes.</p>
                        </dl>
                    </div>

                    <div>
                        <h3 style={{ marginBottom: 'var(--space-md)' }}>
                            Satin ribbon 20mm, last four movements
                        </h3>
                        <div className="rows__head" aria-hidden="true">
                            <span>Time</span>
                            <span>Movement</span>
                            <span>Change</span>
                            <span>Balance</span>
                        </div>
                        <ul className="rows">
                            {LEDGER.map((r) => (
                                <li
                                    key={`${r.time}-${r.what}`}
                                    className={r.fix ? 'rows__item rows__item--fix' : 'rows__item'}
                                >
                                    <span className="rows__time">
                                        <time>{r.time}</time>
                                    </span>
                                    <span className="rows__what">
                                        {r.what}
                                        <small>{r.note}</small>
                                    </span>
                                    <span className="rows__delta num">
                                        <span className="rows__label">Change</span>
                                        {r.delta}
                                    </span>
                                    <span className="rows__bal num">
                                        <span className="rows__label">Balance</span>
                                        {r.bal}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            </div>
        </section>
    );
}

/* -------------------------------------------------------------------- steps */

const STEPS = [
    {
        title: 'Connect your store',
        body: 'Install the bridge plugin, paste your API key and secret. Orders and products start syncing.',
    },
    {
        title: 'Map your recipes',
        body: 'Attach a recipe to each product: its materials, sub-products and how many per unit.',
    },
    {
        title: 'Let it run',
        body: 'Orders reserve and deduct stock. Forecasting flags what will run out and what to reorder.',
    },
];

function Steps() {
    return (
        <section id="how" className="sec">
            <div className="container">
                <SectionHead
                    title="Live inventory in an afternoon"
                    line="No migration and no data entry marathon."
                    aside={
                        <p className="fine">
                            Connect, map, and go. The first synced order is usually the same day.
                        </p>
                    }
                />

                <ol className="steps">
                    {STEPS.map((s, i) => (
                        <li key={s.title} className="step">
                            <span className="step__n" aria-hidden="true">
                                {i + 1}
                            </span>
                            <h3>{s.title}</h3>
                            <p>{s.body}</p>
                        </li>
                    ))}
                </ol>

                <dl className="also">
                    <div>
                        <dt>Forecasting</dt>
                        <dd>Runway per component</dd>
                    </div>
                    <div>
                        <dt>Analytics</dt>
                        <dd>Every movement, by item</dd>
                    </div>
                    <div>
                        <dt>Valuation</dt>
                        <dd>Stock at live cost</dd>
                    </div>
                    <div>
                        <dt>Restock</dt>
                        <dd>What to buy, and when</dd>
                    </div>
                </dl>
            </div>
        </section>
    );
}

/* ------------------------------------------------------------------- bridge */

const CROSSES = ['Orders and line items', 'Catalogue and prices', 'Stock levels'];
const NEVER = ['Status updates', 'Stock writes', 'Post and product meta'];

function Bridge() {
    return (
        <section id="bridge" className="sec sec--paper-2">
            <div className="container">
                <SectionHead
                    title="Your store is never written to"
                    line="The plugin reads and forwards. Everything else happens in StockPilot."
                    aside={
                        <p className="fine">
                            Orders and catalogue travel over signed webhooks. Nothing travels back, so your
                            storefront behaves exactly as it did.
                        </p>
                    }
                />

                <div className="bridge">
                    <div className="bridge__side">
                        <h3>Crosses the bridge</h3>
                        <p>Read from WooCommerce, then sent with a signature the portal verifies.</p>
                        <ul className="bridge__list">
                            {CROSSES.map((c) => (
                                <li key={c}>
                                    <span>{c}</span>
                                    <span>Signed</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                    <div className="bridge__side bridge__side--never">
                        <span className="bridge__never-mark" aria-hidden="true" />
                        <h3>Never crosses</h3>
                        <p>
                            A read-only bridge, by design. No function in the plugin calls a WooCommerce
                            writer.
                        </p>
                        <ul className="bridge__list">
                            {NEVER.map((c) => (
                                <li key={c}>
                                    <span>{c}</span>
                                    <span>Blocked</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                    <p className="bridge__foot">
                        Deactivating the plugin leaves the store exactly as it was. Nothing to roll back.
                    </p>
                </div>
            </div>
        </section>
    );
}

/* ---------------------------------------------------------------------- faq */

const FAQ = [
    {
        q: 'Do I need to change my WooCommerce store?',
        a: 'No. The StockPilot plugin is read-only: it reads orders and catalogue data and forwards them over HMAC-signed webhooks. It never calls a WooCommerce writer, never updates order status, and never touches stock functions or post meta. Deactivating it leaves the store exactly as it was.',
    },
    {
        q: 'How are kit products handled?',
        a: 'Each sellable product gets a recipe listing the materials and sub-products it consumes. When an order arrives the recipe is expanded recursively, up to ten levels deep, with cycle detection. A directly-declared component takes precedence over the same component inherited from a sub-product, so packagings do not stack twice.',
    },
    {
        q: 'What happens if stock goes negative?',
        a: 'By default deductions clamp at zero and the order is marked mismatch, so the shortfall is visible. If you would rather record the true oversell, allow_negative_stock can be enabled in Settings.',
    },
    {
        q: 'Can I correct a mistake in the stock history?',
        a: 'Not by editing. The ledger is append-only: a correction is a new movement that points back at the original through reversal_of. That keeps every historical balance reconstructable.',
    },
    {
        q: 'Where does forecasting data come from?',
        a: 'Only from your own movement ledger. The engine computes daily burn, runway and reorder quantities from real orders and adjustments. There is no external model, and nothing leaves the workspace.',
    },
    {
        q: 'Which order statuses move stock?',
        a: 'Pending and on-hold reserve; completed deducts; cancelled and failed release; refunded and returned restock. All four mappings can be remapped to different WooCommerce statuses in Settings.',
    },
    {
        q: 'Do I need a Python service running?',
        a: 'No. A built-in Artisan command computes the same baseline forecast from trailing sales velocity, so shared hosting with only cron works. A Prophet-based worker is optional if you want seasonality.',
    },
    {
        q: 'Will a duplicate webhook deduct stock twice?',
        a: 'No. Every event carries a deterministic id and the engine checks the ledger before reserving or confirming, so a replayed webhook is a no-op.',
    },
    {
        q: 'Can I connect more than one store?',
        a: 'Yes. Each store gets its own key and secret pair, and all of them feed one inventory ledger. Keys can be rotated without reconnecting.',
    },
    {
        q: 'Who can do what?',
        a: 'Three roles. Admins manage everything, including the team and store keys. Managers handle stock, orders, recipes and reports, but not store keys or the team. Staff can move stock and work orders.',
    },
    {
        q: 'Does it run on shared hosting?',
        a: 'Yes. Webhooks are processed inline by default, so no queue worker or supervisor is required, and a single cron entry drives the scheduler.',
    },
];

function Faq() {
    const [open, setOpen] = useState<number | null>(0);
    return (
        <section id="faq" className="sec">
            <div className="container">
                <SectionHead
                    title="Questions, answered"
                    line="The eleven a kit seller asks before connecting a store."
                />
                <div className="faq">
                    {FAQ.map((item, i) => {
                        const isOpen = open === i;
                        return (
                            <div className="faq__row" key={item.q}>
                                <button
                                    type="button"
                                    onClick={() => setOpen(isOpen ? null : i)}
                                    aria-expanded={isOpen}
                                    className="faq__trigger"
                                >
                                    <span className="faq__q">{item.q}</span>
                                    <span className="faq__mark" aria-hidden="true">
                                        {isOpen ? '−' : '+'}
                                    </span>
                                </button>
                                {isOpen && <p className="faq__answer">{item.a}</p>}
                            </div>
                        );
                    })}
                </div>
            </div>
        </section>
    );
}

/* -------------------------------------------------------------------- close */

function Close() {
    return (
        <section className="close">
            <div className="container">
                <div className="close__grid">
                    <div>
                        <h2>Take the guesswork out of your stock</h2>
                        <p>
                            Create a workspace, connect your store, and see every component, recipe and
                            order in one place.
                        </p>
                    </div>
                    <div className="close__actions">
                        <Link to="/register" className="btn btn--accent">
                            Create your workspace
                        </Link>
                        <Link to="/login" className="btn btn--on-ink">
                            Sign in
                        </Link>
                    </div>
                </div>
            </div>
        </section>
    );
}

export default function Landing() {
    return (
        <>
            <Hero />
            <Recipes />
            <Ledger />
            <Steps />
            <Bridge />
            <Faq />
            <Close />
        </>
    );
}
