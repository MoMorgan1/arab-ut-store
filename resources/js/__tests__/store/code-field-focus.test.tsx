import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it } from 'vitest';

import { CodeFields } from '@/components/configurator/manual-services/credentials-fields';
import {
    focusSiblingCodeField,
    siblingCodeFields,
} from '@/lib/code-field-focus';
import type { ManualServiceCommonTranslations } from '@/types/manual-services';

afterEach(cleanup);

function mountInputs() {
    document.body.innerHTML = `
        <input data-code-field="" data-code-group="ea" value="" />
        <input data-code-field="" data-code-group="ea" value="" />
        <input data-code-field="" data-code-group="ea" value="" />
        <input data-code-field="" data-code-group="ps" value="" />
        <input type="email" value="" />
    `;

    return Array.from(
        document.body.querySelectorAll('input'),
    ) as HTMLInputElement[];
}

describe('siblingCodeFields', () => {
    it('groups by data-code-group in DOM order and skips other fields', () => {
        const [first, second, third] = mountInputs();

        expect(siblingCodeFields(first!)).toHaveLength(3);
        expect(siblingCodeFields(first!)[1]).toBe(second);
        expect(siblingCodeFields(first!)[2]).toBe(third);
    });
});

describe('focusSiblingCodeField', () => {
    it('advances within the group and selects the neighbour', () => {
        const [first, second] = mountInputs();

        first!.focus();
        focusSiblingCodeField(first!, 1);

        expect(document.activeElement).toBe(second);
        expect(second!.selectionStart).toBe(0);
        expect(second!.selectionEnd).toBe(second!.value.length);
    });

    it('retreats to the previous field', () => {
        const [first, second] = mountInputs();

        second!.focus();
        focusSiblingCodeField(second!, -1);

        expect(document.activeElement).toBe(first);
    });

    it('does nothing past the last field and never lands on other inputs', () => {
        const inputs = mountInputs();
        const last = inputs[2]!;

        last.focus();
        focusSiblingCodeField(last, 1);

        expect(document.activeElement).toBe(last);

        const foreign = inputs[3]!;
        foreign.focus();
        focusSiblingCodeField(foreign, 1);

        expect(document.activeElement).toBe(foreign);
    });
});

const translations = {
    backup_code: 'Backup code :number',
} as ManualServiceCommonTranslations;

function CodeHarness() {
    const [codes, setCodes] = useState<[string, string, string]>(['', '', '']);

    return (
        <CodeFields
            codes={codes}
            fieldPrefix="eaCode"
            label="EA codes"
            namePrefix="ea-code"
            numeric
            onChange={setCodes}
            translations={translations}
            tutorialHref="https://example.test/ea"
            tutorialLabel="EA tutorial"
        />
    );
}

describe('CodeFields auto-advance', () => {
    it('focuses the next code once eight digits are typed', () => {
        render(<CodeHarness />);

        const fields = screen.getAllByLabelText(/Backup code/);
        fireEvent.change(fields[0]!, { target: { value: '12345678' } });

        expect(fields[0]).toHaveValue('12345678');
        expect(document.activeElement).toBe(fields[1]);
    });

    it('moves back on Backspace in an empty field', () => {
        render(<CodeHarness />);

        const fields = screen.getAllByLabelText(/Backup code/);
        (fields[1] as HTMLInputElement).focus();
        fireEvent.keyDown(fields[1]!, { key: 'Backspace' });

        expect(document.activeElement).toBe(fields[0]);
    });

    it('stays put on the last completed code', () => {
        render(<CodeHarness />);

        const fields = screen.getAllByLabelText(/Backup code/);
        (fields[2] as HTMLInputElement).focus();
        fireEvent.change(fields[2]!, { target: { value: '87654321' } });

        expect(fields[2]).toHaveValue('87654321');
        expect(document.activeElement).toBe(fields[2]);
    });
});
