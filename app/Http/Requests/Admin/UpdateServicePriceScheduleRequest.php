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
        } elseif ($serviceType === ServiceType::Coins->value) {
            // Shape only. Whether the bands ascend, divide evenly and cover the
            // presets is CoinsQuantityRules' job, checked inside the transaction.
            $rules['configuration.minimum'] = ['required', 'integer', 'min:1'];
            $rules['configuration.tiers'] = ['required', 'array', 'min:1'];
            $rules['configuration.tiers.*'] = ['required', 'array'];
            $rules['configuration.tiers.*.upTo'] = ['required', 'integer', 'min:1'];
            $rules['configuration.tiers.*.step'] = ['required', 'integer', 'min:1'];
            $rules['configuration.presets'] = ['present', 'array'];
            $rules['configuration.presets.*'] = ['required', 'integer', 'min:1'];
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
                $allowedConfigKeys = ['steps'];
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

            return [
                'steps' => $steps,
            ];
        }

        return $config;
    }
}
