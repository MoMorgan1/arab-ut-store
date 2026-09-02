<?php

namespace App\Admin\Actions;

use App\Actions\Store\ValidateStoreInformationPage;
use App\Admin\Audit\StaffAuditEvent;
use App\Enums\AdminPermission;
use App\Models\StorePage;
use App\Models\User;
use App\Services\Content\StoreInformationMarkup;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

final readonly class UpdateAdminStorePage
{
    public function __construct(
        private RecordStaffAudit $recordStaffAudit,
        private ValidateStoreInformationPage $validator,
    ) {}

    /**
     * @param array{
     *     ar: array{
     *         title: string,
     *         subtitle?: ?string,
     *         updatedLabel: string,
     *         blocks: list<array<string, mixed>>
     *     },
     *     en: array{
     *         title: string,
     *         subtitle?: ?string,
     *         updatedLabel: string,
     *         blocks: list<array<string, mixed>>
     *     }
     * } $data
     */
    public function execute(User $actor, string $key, array $data, ?string $ipAddress = null): StorePage
    {
        if (! $actor->is_active || ! $actor->can(AdminPermission::MarketingManage->value)) {
            throw new AuthorizationException('This action requires marketing.manage permission.');
        }

        try {
            $blocksAr = StoreInformationMarkup::blocksFromEditor($data['ar']['blocks']);
            $blocksEn = StoreInformationMarkup::blocksFromEditor($data['en']['blocks']);

            $subtitleAr = isset($data['ar']['subtitle']) && trim((string) $data['ar']['subtitle']) !== ''
                ? trim((string) $data['ar']['subtitle'])
                : null;
            $subtitleEn = isset($data['en']['subtitle']) && trim((string) $data['en']['subtitle']) !== ''
                ? trim((string) $data['en']['subtitle'])
                : null;

            $metaAr = (array) trans('store_pages.meta', locale: 'ar');
            $metaEn = (array) trans('store_pages.meta', locale: 'en');

            $this->validator->validate(
                $key,
                [
                    'title' => $data['ar']['title'],
                    'subtitle' => $subtitleAr,
                    'updated_label' => $data['ar']['updatedLabel'],
                    'blocks' => $blocksAr,
                ],
                $metaAr,
                config('store.support.whatsapp_url'),
            );

            $this->validator->validate(
                $key,
                [
                    'title' => $data['en']['title'],
                    'subtitle' => $subtitleEn,
                    'updated_label' => $data['en']['updatedLabel'],
                    'blocks' => $blocksEn,
                ],
                $metaEn,
                config('store.support.whatsapp_url'),
            );
        } catch (LogicException|InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'error' => [$e->getMessage()],
            ]);
        }

        return DB::transaction(function () use ($actor, $key, $data, $blocksAr, $blocksEn, $subtitleAr, $subtitleEn, $ipAddress): StorePage {
            /** @var StorePage $page */
            $page = StorePage::query()
                ->where('key', $key)
                ->lockForUpdate()
                ->firstOrFail();

            $previousBlocksAr = $page->blocks_ar;
            $previousBlocksEn = $page->blocks_en;

            $page->title_ar = $data['ar']['title'];
            $page->title_en = $data['en']['title'];
            $page->subtitle_ar = $subtitleAr;
            $page->subtitle_en = $subtitleEn;
            $page->updated_label_ar = $data['ar']['updatedLabel'];
            $page->updated_label_en = $data['en']['updatedLabel'];
            $page->blocks_ar = $blocksAr;
            $page->blocks_en = $blocksEn;
            $page->save();

            $this->recordStaffAudit->execute(
                $actor,
                $page,
                new StaffAuditEvent(
                    action: 'store_pages.updated',
                    metadata: [
                        'previous_blocks_ar' => $previousBlocksAr,
                        'previous_blocks_en' => $previousBlocksEn,
                    ],
                    ipAddress: $ipAddress,
                ),
            );

            return $page;
        });
    }
}
