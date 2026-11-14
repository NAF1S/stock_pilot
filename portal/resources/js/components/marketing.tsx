/**
 * Wayfind marketing primitives.
 * Sentence-case labels in the body face, square rules, no eyebrows:
 * a section opens on its heading, with an optional line beside it.
 */

import type { ReactNode } from 'react';

export function SectionHead({
    id,
    title,
    line,
    aside,
}: {
    id?: string;
    title: string;
    line?: string;
    aside?: ReactNode;
}) {
    return (
        <div className="sec__head">
            <div>
                <h2 id={id}>{title}</h2>
                {line && (
                    <p className="lead" style={{ marginTop: 'var(--space-md)' }}>
                        {line}
                    </p>
                )}
            </div>
            {aside ? <div>{aside}</div> : <span aria-hidden="true" />}
        </div>
    );
}
