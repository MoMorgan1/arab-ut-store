/**
 * Placeholder cards shown while the catalog reloads after a filter, search,
 * or page change. Each skeleton mirrors the compact SBC card shell so the
 * layout does not jump when the real products arrive.
 */
export function CatalogSkeletonGrid({ count }: { count: number }) {
    return (
        <>
            {Array.from({ length: count }, (_, index) => (
                <li
                    aria-hidden="true"
                    className={[
                        'store-catalog-card',
                        'store-catalog-card--sbc',
                        'store-catalog-card--compact',
                        'store-catalog-skeleton',
                    ].join(' ')}
                    key={index}
                >
                    <div className="store-catalog-card__target">
                        <div className="store-catalog-card__media store-catalog-skeleton__media">
                            <span className="store-catalog-skeleton__image" />
                        </div>
                        <div className="store-catalog-card__body">
                            <span className="store-catalog-skeleton__included" />
                            <span className="store-catalog-skeleton__title" />
                            <span className="store-catalog-skeleton__title store-catalog-skeleton__title--short" />
                            <span className="store-catalog-card__prices store-catalog-skeleton__prices">
                                <span className="store-catalog-skeleton__price" />
                                <span className="store-catalog-skeleton__price" />
                            </span>
                        </div>
                    </div>
                </li>
            ))}
        </>
    );
}
