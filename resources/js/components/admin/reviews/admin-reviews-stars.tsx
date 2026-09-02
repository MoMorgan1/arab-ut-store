import { Star } from 'lucide-react';
import React from 'react';

const RATINGS = [1, 2, 3, 4, 5];

export default function AdminReviewStars({
    label,
    rating,
}: {
    label: string;
    rating: number;
}) {
    return (
        <span
            aria-label={label}
            className="inline-flex items-center gap-0.5"
            role="img"
        >
            {RATINGS.map((star) => (
                <Star
                    aria-hidden="true"
                    className={
                        star <= rating
                            ? 'size-3.5 shrink-0 fill-current text-status-warning'
                            : 'size-3.5 shrink-0 text-muted-foreground/50'
                    }
                    key={star}
                />
            ))}
        </span>
    );
}
