import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import OneTimeCodeField from '@/components/one-time-code-field';

afterEach(cleanup);

describe('OneTimeCodeField', () => {
    it('advances focus as digits are typed and calls onComplete when all 6 cells are filled', () => {
        const onComplete = vi.fn();

        function ControlledOtp() {
            const [code, setCode] = useState('');

            return (
                <OneTimeCodeField
                    id="code"
                    label="Verification code"
                    onChange={setCode}
                    onComplete={onComplete}
                    value={code}
                />
            );
        }

        render(<ControlledOtp />);

        const cell1 = screen.getByLabelText('Verification code 1/6');
        const cell2 = screen.getByLabelText('Verification code 2/6');
        const cell3 = screen.getByLabelText('Verification code 3/6');
        const cell4 = screen.getByLabelText('Verification code 4/6');
        const cell5 = screen.getByLabelText('Verification code 5/6');
        const cell6 = screen.getByLabelText('Verification code 6/6');

        cell1.focus();
        expect(cell1).toHaveFocus();

        fireEvent.change(cell1, { target: { value: '4' } });
        expect(cell2).toHaveFocus();

        fireEvent.change(cell2, { target: { value: '1' } });
        expect(cell3).toHaveFocus();

        fireEvent.change(cell3, { target: { value: '9' } });
        expect(cell4).toHaveFocus();

        fireEvent.change(cell4, { target: { value: '2' } });
        expect(cell5).toHaveFocus();

        fireEvent.change(cell5, { target: { value: '7' } });
        expect(cell6).toHaveFocus();

        fireEvent.change(cell6, { target: { value: '3' } });

        expect(onComplete).toHaveBeenCalledTimes(1);
        expect(onComplete).toHaveBeenCalledWith('419273');
    });

    it('fills all six cells and calls onComplete once when pasting >=6 digits', () => {
        const onChange = vi.fn();
        const onComplete = vi.fn();

        render(
            <OneTimeCodeField
                id="code"
                label="Verification code"
                onChange={onChange}
                onComplete={onComplete}
                value=""
            />,
        );

        const cell1 = screen.getByLabelText('Verification code 1/6');
        fireEvent.paste(cell1, {
            clipboardData: {
                getData: () => '419273',
            },
        });

        expect(onChange).toHaveBeenCalledWith('419273');
        expect(onComplete).toHaveBeenCalledTimes(1);
        expect(onComplete).toHaveBeenCalledWith('419273');
    });

    it('moves focus back and clears previous cell on backspace', () => {
        function ControlledOtp() {
            const [code, setCode] = useState('41');

            return (
                <OneTimeCodeField
                    id="code"
                    label="Verification code"
                    onChange={setCode}
                    value={code}
                />
            );
        }

        render(<ControlledOtp />);

        const cell2 = screen.getByLabelText('Verification code 2/6');
        const cell3 = screen.getByLabelText('Verification code 3/6');

        cell3.focus();
        fireEvent.keyDown(cell3, { key: 'Backspace' });

        expect(cell2).toHaveFocus();
        expect(cell2).toHaveValue('');
    });

    it('ignores non-digit characters', () => {
        const onChange = vi.fn();

        render(
            <OneTimeCodeField
                id="code"
                label="Verification code"
                onChange={onChange}
                value=""
            />,
        );

        const cell1 = screen.getByLabelText('Verification code 1/6');
        fireEvent.keyDown(cell1, { key: 'a' });
        fireEvent.change(cell1, { target: { value: 'a' } });

        expect(onChange).toHaveBeenCalledWith('');
    });

    it('renders a hidden input carrying the value when name is provided', () => {
        const { container } = render(
            <OneTimeCodeField
                id="code"
                label="Verification code"
                name="code"
                onChange={() => {}}
                value="419273"
            />,
        );

        const hiddenInput = container.querySelector(
            'input[type="hidden"][name="code"]',
        );
        expect(hiddenInput).toBeInTheDocument();
        expect(hiddenInput).toHaveValue('419273');
    });
});
