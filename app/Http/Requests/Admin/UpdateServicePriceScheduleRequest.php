<?php

namespace App\Http\Requests\Admin;

use App\Enums\AdminPermission;
use App\Enums\ServiceType;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateServicePriceScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can(AdminPermission::SettingsManage->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $serviceType = (string) $this->route('serviceType');

        $rules = [
            'expected_version' => ['required', 'integer', 'min:1'],
            'configuration' => ['required', 'array'],
        ];

        if ($serviceType === ServiceType::FutChampions->value) {
            $rules['configuration.ranks'] = ['required', 'array'];
            for ($rank = 1; $rank <= 6; $rank++) {
                $rules["configuration.ranks.{$rank}"] = ['required', 'integer', 'min:1'];
            }
            $rules['configuration.urgent_surcharge_halalah'] = ['required', 'integer', 'min:1'];
        } elseif ($serviceType === ServiceType::Rivals->value) {
            $rules['configuration.steps'] = ['required', 'array'];
            $steps = ['7:6', '6:5', '5:4', '4:3', '3:2', '2:1', '1:elite'];
            foreach ($steps as $step) {
                $rules["configuration.steps.{$step}"] = ['required', 'integer', 'min:1'];
            }
            // Weekly matches are optional: absent means not on sale, and the
            // storefront hides the option entirely. Half-filled is refused,
            // because a price with no win count promises nothing.
            $rules['configuration.weeklyMatches'] = ['sometimes', 'nullable', 'array'];
            $rules['configuration.weeklyMatches.priceHalalah'] = ['required_with:configuration.weeklyMatches', 'integer', 'min:1'];
            $rules['configuration.weeklyMatches.includedWins'] = ['required_with:configuration.weeklyMatches', 'integer', 'min:1'];
        } elseif ($serviceType === ServiceType::Coins->value) {
            // Shape only. Whether the bands ascend, divide evenly and cover the
            // presets is CoinsQuantityRules' job, checked inside the transaction.
            $rules['configuration.minimum'] = ['required', 'integer', 'min:1'];
            $rules['configuration.roundingUnit'] = ['required', 'integer', 'min:1'];
            $rules['configuration.tiers'] = ['required', 'array', 'min:1'];
            $rules['configuration.tiers.*'] = ['required', 'array'];
            $rules['configuration.tiers.*.upTo'] = ['required', 'integer', 'min:1'];
            $rules['configuration.tiers.*.step'] = ['required', 'integer', 'min:1'];
            $rules['configuration.presets'] = ['present', 'array'];
            $rules['configuration.presets.*'] = ['required', 'integer', 'min:1'];
            // Optional flag: absent means the credentials step does not ask
            // for the account's current Coins balance on fast console orders.
            $rules['configuration.requiresCurrentBalance'] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $serviceType = (string) $this->route('serviceType');

            $allowedTopKeys = ['expected_version', 'configuration'];
            $extraTopKeys = array_diff(array_keys($this->all()), $allowedTopKeys);

            if (! empty($extraTopKeys)) {
                $validator->errors()->add('unexpected_fields', 'Unknown fields are not allowed.');

                return;
            }

            $rawConfig = $this->input('configuration');
            if (! is_array($rawConfig)) {
                return;
            }

            if ($serviceType === ServiceType::FutChampions->value) {
                $allowedConfigKeys = ['ranks', 'urgent_surcharge_halalah'];
                $extraConfigKeys = array_diff(array_keys($rawConfig), $allowedConfigKeys);
                if (! empty($extraConfigKeys)) {
                    $validator->errors()->add('unexpected_fields', 'Unknown configuration fields are not allowed.');

                    return;
                }

                $rawRanks = $rawConfig['ranks'] ?? null;
                if (is_array($rawRanks)) {
                    $allowedRanks = ['1', '2', '3', '4', '5', '6'];
                    $rankKeys = array_map('strval', array_keys($rawRanks));
                    $extraRanks = array_diff($rankKeys, $allowedRanks);
                    if (! empty($extraRanks)) {
                        $validator->errors()->add('unexpected_fields', 'Unknown rank keys are not allowed.');
                    }
                }
            } elseif ($serviceType === ServiceType::Rivals->value) {
                $allowedConfigKeys = ['steps', 'weeklyMatches'];
                $extraConfigKeys = array_diff(array_keys($rawConfig), $allowedConfigKeys);
                if (! empty($extraConfigKeys)) {
                    $validator->errors()->add('unexpected_fields', 'Unknown configuration fields are not allowed.');

                    return;
                }

                $rawSteps = $rawConfig['steps'] ?? null;
                if (is_array($rawSteps)) {
                    $allowedSteps = ['7:6', '6:5', '5:4', '4:3', '3:2', '2:1', '1:elite'];
                    $stepKeys = array_map('strval', array_keys($rawSteps));
                    $extraSteps = array_diff($stepKeys, $allowedSteps);
                    if (! empty($extraSteps)) {
                        $validator->errors()->add('unexpected_fields', 'Unknown step keys are not allowed.');
                    }
                }
            } elseif ($serviceType === ServiceType::Coins->value) {
                $allowedConfigKeys = ['minimum', 'roundingUnit', 'tiers', 'presets', 'requiresCurrentBalance'];
                $extraConfigKeys = array_diff(array_keys($rawConfig), $allowedConfigKeys);
                if (! empty($extraConfigKeys)) {
                    $validator->errors()->add('unexpected_fields', 'Unknown configuration fields are not allowed.');

                    return;
                }

                foreach ((array) ($rawConfig['tiers'] ?? []) as $tier) {
                    if (! is_array($tier)) {
                        continue;
                    }

                    $extraTierKeys = array_diff(array_keys($tier), ['upTo', 'step']);
                    if (! empty($extraTierKeys)) {
                        $validator->errors()->add('unexpected_fields', 'Unknown band fields are not allowed.');

                        return;
                    }
                }
            }
        });
    }

    public function expectedVersion(): int
    {
        return (int) $this->input('expected_version');
    }

    /**
     * @return array<string, mixed>
     */
    public function configuration(): array
    {
        $config = (array) $this->input('configuration');
        $serviceType = (string) $this->route('serviceType');

        if ($serviceType === ServiceType::FutChampions->value) {
            $ranks = [];
            foreach ((array) ($config['ranks'] ?? []) as $k => $v) {
                $ranks[(int) $k] = (int) $v;
            }

            return [
                'ranks' => $ranks,
                'urgent_surcharge_halalah' => (int) ($config['urgent_surcharge_halalah'] ?? 0),
            ];
        }

        if ($serviceType === ServiceType::Rivals->value) {
            $steps = [];
            foreach ((array) ($config['steps'] ?? []) as $k => $v) {
                $steps[(string) $k] = (int) $v;
            }

            $weeklyMatches = $config['weeklyMatches'] ?? null;

            if (! is_array($weeklyMatches) || $weeklyMatches === []) {
                return ['steps' => $steps];
            }

            return [
                'steps' => $steps,
                'weeklyMatches' => [
                    'priceHalalah' => (int) ($weeklyMatches['priceHalalah'] ?? 0),
                    'includedWins' => (int) ($weeklyMatches['includedWins'] ?? 0),
                ],
            ];
        }

        // The reader compares the toggle with === true, so a truthy "1" from
        // a non-JSON client must not save as a value that silently reads off.
        if ($serviceType === ServiceType::Coins->value
            && array_key_exists('requiresCurrentBalance', $config)) {
            $config['requiresCurrentBalance'] = filter_var(
                $config['requiresCurrentBalance'],
                FILTER_VALIDATE_BOOLEAN,
            );
        }

        return $config;
    }
}
