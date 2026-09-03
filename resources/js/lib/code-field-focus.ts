/**
 * Backup-code auto-advance: every code input carries `data-code-field` and a
 * `data-code-group` naming its group. Moving between fields uses DOM order
 * only — never the `registerFieldRef` map, whose inline ref callback is
 * re-created every render — and only ever lands on another code input, so
 * email/password/balance fields are unreachable.
 */
export function siblingCodeFields(input: HTMLInputElement): HTMLInputElement[] {
    const group = input.getAttribute('data-code-group') ?? '';
    const root = input.getRootNode() as Document | ShadowRoot;
    const scope =
        root instanceof Document || root instanceof ShadowRoot
            ? root
            : document;

    return Array.from(
        scope.querySelectorAll<HTMLInputElement>(
            'input[data-code-field][data-code-group]',
        ),
    ).filter(
        (candidate) => candidate.getAttribute('data-code-group') === group,
    );
}

export function focusSiblingCodeField(
    input: HTMLInputElement,
    direction: 1 | -1,
): void {
    const fields = siblingCodeFields(input);
    const index = fields.indexOf(input);

    if (index === -1) {
        return;
    }

    const neighbour = fields[index + direction];

    if (neighbour !== undefined && !neighbour.disabled && !neighbour.readOnly) {
        neighbour.focus();

        if (direction === 1) {
            neighbour.select();
        } else {
            // Backspace retreat: the caret lands after the last character so
            // the next Backspace removes one digit, not the whole code.
            const end = neighbour.value.length;
            neighbour.setSelectionRange(end, end);
        }
    }
}
