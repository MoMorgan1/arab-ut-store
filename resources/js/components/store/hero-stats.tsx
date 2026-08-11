import { useEffect, useRef, useState } from 'react';

type HeroStat = { label: string; unit: string; value: string };

export function HeroStats({
    label,
    stats,
}: {
    label: string;
    stats: HeroStat[];
}) {
    const proofRef = useRef<HTMLDListElement>(null);
    const [progress, setProgress] = useState(() =>
        typeof IntersectionObserver === 'undefined' ? 1 : 0,
    );

    useEffect(() => {
        if (
            typeof IntersectionObserver === 'undefined' ||
            proofRef.current === null
        ) {
            return;
        }

        let timer: number | undefined;

        const observer = new IntersectionObserver(([entry]) => {
            if (!entry.isIntersecting) {
                return;
            }

            observer.disconnect();

            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                setProgress(1);

                return;
            }

            let frame = 0;

            timer = window.setInterval(() => {
                frame += 1;
                setProgress(Math.min(frame / 24, 1));

                if (frame === 24) {
                    window.clearInterval(timer);
                }
            }, 40);
        });

        observer.observe(proofRef.current);

        return () => {
            observer.disconnect();

            if (timer !== undefined) {
                window.clearInterval(timer);
            }
        };
    }, []);

    return (
        <dl
            aria-label={label}
            className="store-hero__stats"
            ref={proofRef}
            role="group"
        >
            {stats.map((stat) => (
                <div
                    className="store-hero__stat"
                    key={`${stat.value}-${stat.label}`}
                >
                    <dd>
                        <bdi className="store-hero__stat-value" dir="ltr">
                            {heroStatValue(stat.value, progress)}
                        </bdi>
                        {stat.unit === '' ? null : <span>{stat.unit}</span>}
                    </dd>
                    <dt>{stat.label}</dt>
                </div>
            ))}
        </dl>
    );
}

function heroStatValue(value: string, progress: number): string {
    if (progress === 0) {
        return '0';
    }

    if (progress === 1) {
        return value;
    }

    const numericText = value.match(/[0-9][0-9,.]*/)?.[0];

    if (numericText === undefined) {
        return value;
    }

    const target = Number(numericText.replaceAll(',', ''));
    const decimals = numericText.split('.')[1]?.length ?? 0;
    const formatted = new Intl.NumberFormat('en-US', {
        maximumFractionDigits: decimals,
        minimumFractionDigits: decimals,
    }).format(target * progress);

    return value.replace(numericText, formatted);
}
