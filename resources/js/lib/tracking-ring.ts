/**
 * The living gold ring, ported from the tracker's `assets/js/ring.js`.
 *
 * It draws the order's progress as an arc with a travelling shimmer, embers
 * lifting off the arc's tip while work is moving, and a coin burst when the
 * order completes. The CSS ring underneath stays as the fallback: if a canvas
 * cannot be created the page still shows progress, just without the motion.
 *
 * Two departures from the tracker's script, both because this one lives inside
 * a component that mounts and unmounts:
 *
 * - It is a class you attach to one element and later destroy, rather than a
 *   module that scans the document once. A page can carry several rings.
 * - Every listener and observer it registers is released on destroy. The
 *   tracker's page never unmounts, so its script never needed to.
 */

type RingMode = 'progress' | 'success' | 'danger' | 'warning';

type Palette = { core: string; hot: string; glow: string; ember: string };

/** The store's gold in place of the tracker's yellow; the rest follows its roles. */
const PALETTE: Record<RingMode, Palette> = {
    progress: {
        core: '#d4a843',
        hot: '#e4b756',
        glow: 'rgba(212,168,67,',
        ember: '228,183,86',
    },
    success: {
        core: '#78d59e',
        hot: '#a3f3c2',
        glow: 'rgba(120,213,158,',
        ember: '163,243,194',
    },
    danger: {
        // The tracker's red. The store's --arabut-danger is a pale pink tuned
        // for body text on a dark ground, and on a 96px ring it reads as
        // decoration rather than as something being wrong.
        core: '#ef4444',
        hot: '#fca5a5',
        glow: 'rgba(239,68,68,',
        ember: '252,165,165',
    },
    warning: {
        core: '#f59e0b',
        hot: '#fcd34d',
        glow: 'rgba(245,158,11,',
        ember: '251,191,36',
    },
};

/** The canvas is drawn twice the ring's size so its glow fades instead of clipping. */
const PAD = 2;

export type RingOptions = {
    percent: number;
    mode: RingMode;
    /** Whether work is moving: a still order keeps its arc but stops throwing embers. */
    active: boolean;
    /** For an order that has reported nothing, where a percentage would be a guess. */
    indeterminate: boolean;
};

type Ember = {
    x: number;
    y: number;
    vx: number;
    vy: number;
    r: number;
    life: number;
    decay: number;
    ember: string;
};

type Spark = {
    x: number;
    y: number;
    vx: number;
    vy: number;
    g: number;
    r: number;
    rot: number;
    vr: number;
    life: number;
    coin: boolean;
    pal: Palette;
};

type Shock = { r: number; max: number; life: number; pal: Palette };

function prefersReduced(): boolean {
    return (
        typeof window !== 'undefined' &&
        typeof window.matchMedia === 'function' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );
}

export class TrackingRing {
    private canvas: HTMLCanvasElement | null = null;
    private ctx: CanvasRenderingContext2D | null = null;
    private dpr = 1;
    private size = 0;
    private box = 0;
    private raf: number | null = null;
    private visible = true;
    private readonly reduceMotion = prefersReduced();
    private lastT = 0;
    private destroyed = false;

    private observer: IntersectionObserver | null = null;
    private readonly onResize = () => {
        this.resize();
        this.repaint();
        this.ensureLoop();
    };

    private readonly onVisibility = () => {
        if (document.hidden) {
            this.stop();
        } else {
            this.ensureLoop();
        }
    };

    private state: RingOptions & {
        targetPct: number;
        spin: number;
        shimmer: number;
        intensity: number;
    };

    private embers: Ember[] = [];
    private sparks: Spark[] = [];
    private shocks: Shock[] = [];

    constructor(
        private readonly container: HTMLElement,
        options: RingOptions,
    ) {
        this.state = {
            ...options,
            targetPct: options.percent,
            spin: 0,
            shimmer: 0,
            intensity: options.percent / 100,
        };

        this.init();
    }

    /**
     * Move the ring to a new reading. The arc eases to the new percentage rather
     * than jumping, so a refresh that lands while the customer is looking reads
     * as the order moving rather than as the page redrawing.
     */
    set(options: Partial<RingOptions>): void {
        if (this.destroyed) {
            return;
        }

        if (options.percent !== undefined) {
            this.state.targetPct = options.percent;
        }

        if (options.mode !== undefined) {
            this.state.mode = options.mode;
        }

        if (options.active !== undefined) {
            this.state.active = options.active;
        }

        if (options.indeterminate !== undefined) {
            this.state.indeterminate = options.indeterminate;
        }

        if (this.reduceMotion) {
            this.drawStatic();
        } else {
            this.ensureLoop();
        }
    }

    /** The completion burst: a shockwave and thirty-two coins thrown off the arc. */
    burst(): void {
        if (!this.ctx || this.reduceMotion || this.destroyed) {
            return;
        }

        const cx = this.size / 2;
        const cy = this.size / 2;
        const pal = PALETTE.progress;

        this.shocks.push({
            r: this.box * 0.26,
            max: this.box * 0.85,
            life: 1,
            pal,
        });

        for (let i = 0; i < 32; i++) {
            const a = -Math.PI / 2 + (Math.random() - 0.5) * Math.PI * 1.8;
            const sp = this.box * (0.016 + Math.random() * 0.026);

            this.sparks.push({
                x: cx + Math.cos(a) * this.box * 0.26,
                y: cy + Math.sin(a) * this.box * 0.26,
                vx: Math.cos(a) * sp,
                vy: Math.sin(a) * sp - this.box * 0.01,
                g: this.box * 0.0006,
                r: this.box * (0.018 + Math.random() * 0.024),
                rot: Math.random() * Math.PI,
                vr: (Math.random() - 0.5) * 0.3,
                life: 1,
                coin: Math.random() > 0.3,
                pal,
            });
        }

        this.ensureLoop();
    }

    destroy(): void {
        this.destroyed = true;
        this.stop();
        this.observer?.disconnect();
        this.observer = null;
        window.removeEventListener('resize', this.onResize);
        document.removeEventListener('visibilitychange', this.onVisibility);
        this.canvas?.remove();
        this.canvas = null;
        this.ctx = null;
    }

    private init(): void {
        try {
            this.canvas = document.createElement('canvas');
            this.canvas.className = 'track-ring-canvas';
            this.ctx = this.canvas.getContext('2d');

            if (!this.ctx) {
                this.canvas = null;

                return;
            }

            this.container.appendChild(this.canvas);
            this.container.classList.add('has-canvas-ring');
            this.resize();

            window.addEventListener('resize', this.onResize);
            document.addEventListener('visibilitychange', this.onVisibility);

            // A ring scrolled out of view costs nothing: the loop stops entirely
            // rather than drawing frames nobody is looking at.
            if ('IntersectionObserver' in window) {
                this.observer = new IntersectionObserver(
                    (entries) => {
                        this.visible = entries.some((e) => e.isIntersecting);

                        if (this.visible) {
                            this.ensureLoop();
                        } else {
                            this.stop();
                        }
                    },
                    { threshold: 0.05 },
                );
                this.observer.observe(this.container);
            }

            if (this.reduceMotion) {
                this.drawStatic();
            } else {
                this.ensureLoop();
            }
        } catch {
            // The CSS ring underneath is the fallback, and it is already drawn.
            this.canvas?.remove();
            this.canvas = null;
            this.ctx = null;
        }
    }

    private resize(): void {
        if (!this.canvas || !this.ctx) {
            return;
        }

        const rect = this.container.getBoundingClientRect();
        this.box = Math.max(60, Math.min(rect.width || 76, rect.height || 76));
        this.size = Math.round(this.box * PAD);
        this.dpr = Math.min(window.devicePixelRatio || 1, 2);
        this.canvas.width = Math.round(this.size * this.dpr);
        this.canvas.height = Math.round(this.size * this.dpr);
        this.canvas.style.width = `${this.size}px`;
        this.canvas.style.height = `${this.size}px`;
        this.ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
    }

    private ensureLoop(): void {
        if (
            this.destroyed ||
            this.reduceMotion ||
            !this.visible ||
            document.hidden
        ) {
            return;
        }

        if (this.raf === null) {
            this.lastT = 0;
            this.raf = requestAnimationFrame((t) => this.frame(t));
        }
    }

    private stop(): void {
        if (this.raf !== null) {
            cancelAnimationFrame(this.raf);
            this.raf = null;
        }
    }

    private ringGeom() {
        const cx = this.size / 2;
        const cy = this.size / 2;
        const stroke = Math.max(4, this.box * 0.07);
        const r = this.box / 2 - stroke / 2 - this.box * 0.02;

        return { cx, cy, r, stroke };
    }

    private spawnEmber(tipX: number, tipY: number, pal: Palette): void {
        const a = -Math.PI / 2 - Math.random() * Math.PI;
        const sp = this.box * (0.002 + Math.random() * 0.004);

        this.embers.push({
            x: tipX + (Math.random() - 0.5) * this.box * 0.04,
            y: tipY + (Math.random() - 0.5) * this.box * 0.04,
            vx: Math.cos(a) * sp + (Math.random() - 0.5) * this.box * 0.002,
            vy: Math.sin(a) * sp,
            r: this.box * (0.006 + Math.random() * 0.01),
            life: 1,
            decay: 0.012 + Math.random() * 0.02,
            ember: pal.ember,
        });
    }

    private drawArc(
        g: ReturnType<TrackingRing['ringGeom']>,
        pal: Palette,
    ): { tipAngle: number } | null {
        const ctx = this.ctx;

        if (!ctx) {
            return null;
        }

        const start = -Math.PI / 2;
        const sweep = (this.state.percent / 100) * Math.PI * 2;

        ctx.beginPath();
        ctx.arc(g.cx, g.cy, g.r, 0, Math.PI * 2);
        ctx.strokeStyle = 'rgba(212,168,67,0.14)';
        ctx.lineWidth = g.stroke;
        ctx.stroke();

        if (this.state.percent <= 0.01) {
            return null;
        }

        ctx.save();
        ctx.shadowColor = `${pal.glow}${0.55 + this.state.intensity * 0.4})`;
        ctx.shadowBlur = g.stroke * (1.4 + this.state.intensity * 2.2);
        ctx.beginPath();
        ctx.arc(g.cx, g.cy, g.r, start, start + sweep);
        ctx.strokeStyle = pal.core;
        ctx.lineWidth = g.stroke;
        ctx.lineCap = 'round';
        ctx.stroke();
        ctx.restore();

        const shPos = this.state.shimmer * sweep;
        const shLen = Math.min(sweep, 0.5);
        ctx.save();
        ctx.beginPath();
        ctx.arc(
            g.cx,
            g.cy,
            g.r,
            start + shPos - shLen / 2,
            start + shPos + shLen / 2,
        );
        ctx.strokeStyle = pal.hot;
        ctx.globalAlpha = 0.55;
        ctx.lineWidth = g.stroke * 0.7;
        ctx.lineCap = 'round';
        ctx.shadowColor = `${pal.glow}0.9)`;
        ctx.shadowBlur = g.stroke * 1.6;
        ctx.stroke();
        ctx.restore();

        const tx = g.cx + Math.cos(start + sweep) * g.r;
        const ty = g.cy + Math.sin(start + sweep) * g.r;

        if (
            this.state.active &&
            this.state.percent > 1 &&
            this.state.percent < 99.5
        ) {
            ctx.save();
            ctx.fillStyle = pal.hot;
            ctx.shadowColor = `${pal.glow}0.95)`;
            ctx.shadowBlur = g.stroke * (2 + this.state.intensity * 1.5);
            ctx.beginPath();
            ctx.arc(
                tx,
                ty,
                g.stroke * (0.5 + this.state.intensity * 0.18),
                0,
                Math.PI * 2,
            );
            ctx.fill();
            ctx.restore();
        }

        return { tipAngle: start + sweep };
    }

    private drawIndeterminate(
        g: ReturnType<TrackingRing['ringGeom']>,
        pal: Palette,
    ): void {
        const ctx = this.ctx;

        if (!ctx) {
            return;
        }

        ctx.beginPath();
        ctx.arc(g.cx, g.cy, g.r, 0, Math.PI * 2);
        ctx.strokeStyle = 'rgba(212,168,67,0.14)';
        ctx.lineWidth = g.stroke;
        ctx.stroke();

        const head = this.state.spin;
        const len = Math.PI * 0.7;
        ctx.save();
        ctx.shadowColor = `${pal.glow}0.6)`;
        ctx.shadowBlur = g.stroke * 1.8;
        const grad = ctx.createLinearGradient(0, 0, this.size, this.size);
        grad.addColorStop(0, pal.core);
        grad.addColorStop(1, pal.hot);
        ctx.beginPath();
        ctx.arc(g.cx, g.cy, g.r, head, head + len);
        ctx.strokeStyle = grad;
        ctx.lineWidth = g.stroke;
        ctx.lineCap = 'round';
        ctx.stroke();
        ctx.restore();
    }

    private repaint(): void {
        if (!this.ctx) {
            return;
        }

        this.ctx.clearRect(0, 0, this.size, this.size);
        const g = this.ringGeom();
        const pal = PALETTE[this.state.mode] ?? PALETTE.progress;

        if (this.state.indeterminate) {
            this.drawIndeterminate(g, pal);
        } else {
            this.drawArc(g, pal);
        }
    }

    /** One frame, held: what a visitor who asked for no motion sees. */
    private drawStatic(): void {
        if (!this.ctx) {
            return;
        }

        this.ctx.clearRect(0, 0, this.size, this.size);
        const g = this.ringGeom();
        const pal = PALETTE[this.state.mode] ?? PALETTE.progress;
        this.state.percent = this.state.targetPct;

        if (this.state.indeterminate) {
            this.ctx.beginPath();
            this.ctx.arc(g.cx, g.cy, g.r, 0, Math.PI * 2);
            this.ctx.strokeStyle = `${pal.glow}0.5)`;
            this.ctx.lineWidth = g.stroke;
            this.ctx.stroke();

            return;
        }

        this.drawArc(g, pal);
    }

    private frame(t: number): void {
        this.raf = null;

        if (!this.ctx || this.destroyed) {
            return;
        }

        const ctx = this.ctx;
        const dt = this.lastT ? Math.min((t - this.lastT) / 16.67, 3) : 1;
        this.lastT = t;

        const pal = PALETTE[this.state.mode] ?? PALETTE.progress;
        const g = this.ringGeom();

        this.state.percent +=
            (this.state.targetPct - this.state.percent) *
            Math.min(1, 0.12 * dt);

        if (Math.abs(this.state.percent - this.state.targetPct) < 0.05) {
            this.state.percent = this.state.targetPct;
        }

        const wantIntensity = this.state.indeterminate
            ? 0.4
            : this.state.percent / 100;
        this.state.intensity +=
            (wantIntensity - this.state.intensity) * Math.min(1, 0.05 * dt);

        this.state.spin += 0.06 * dt;
        this.state.shimmer += 0.012 * dt;

        if (this.state.shimmer > 1) {
            this.state.shimmer -= 1;
        }

        ctx.clearRect(0, 0, this.size, this.size);

        let tip: { tipAngle: number } | null = null;

        if (this.state.indeterminate) {
            this.drawIndeterminate(g, pal);
        } else {
            tip = this.drawArc(g, pal);
        }

        if (
            tip &&
            this.state.active &&
            this.state.percent > 2 &&
            this.state.percent < 99.5
        ) {
            if (Math.random() < this.state.intensity * 0.9 * dt) {
                this.spawnEmber(
                    g.cx + Math.cos(tip.tipAngle) * g.r,
                    g.cy + Math.sin(tip.tipAngle) * g.r,
                    pal,
                );
            }
        }

        for (let i = this.embers.length - 1; i >= 0; i--) {
            const e = this.embers[i];
            e.x += e.vx * dt;
            e.y += e.vy * dt;
            e.vy -= this.box * 0.00006 * dt;
            e.life -= e.decay * dt;

            if (e.life <= 0) {
                this.embers.splice(i, 1);

                continue;
            }

            ctx.save();
            ctx.globalAlpha = Math.max(0, e.life) * 0.8;
            ctx.fillStyle = `rgba(${e.ember},1)`;
            ctx.shadowColor = `rgba(${e.ember},0.9)`;
            ctx.shadowBlur = e.r * 2.5;
            ctx.beginPath();
            ctx.arc(e.x, e.y, e.r * e.life, 0, Math.PI * 2);
            ctx.fill();
            ctx.restore();
        }

        for (let i = this.shocks.length - 1; i >= 0; i--) {
            const s = this.shocks[i];
            s.r += (s.max - s.r) * 0.08 * dt;
            s.life -= 0.018 * dt;

            if (s.life <= 0) {
                this.shocks.splice(i, 1);

                continue;
            }

            ctx.save();
            ctx.globalAlpha = Math.max(0, s.life) * 0.55;
            ctx.strokeStyle = s.pal.hot;
            ctx.lineWidth = Math.max(1, this.box * 0.014 * s.life);
            ctx.shadowColor = `${s.pal.glow}0.8)`;
            ctx.shadowBlur = this.box * 0.06;
            ctx.beginPath();
            ctx.arc(g.cx, g.cy, s.r, 0, Math.PI * 2);
            ctx.stroke();
            ctx.restore();
        }

        for (let i = this.sparks.length - 1; i >= 0; i--) {
            const p = this.sparks[i];
            p.vy += p.g * dt;
            p.x += p.vx * dt;
            p.y += p.vy * dt;
            p.rot += p.vr * dt;
            p.life -= 0.011 * dt;

            if (p.life <= 0) {
                this.sparks.splice(i, 1);

                continue;
            }

            ctx.save();
            ctx.globalAlpha = Math.max(0, p.life);
            ctx.translate(p.x, p.y);
            ctx.rotate(p.rot);
            ctx.shadowColor = `${p.pal.glow}0.8)`;
            ctx.shadowBlur = p.r * 2;

            if (p.coin) {
                ctx.fillStyle = p.pal.core;
                ctx.beginPath();
                ctx.ellipse(
                    0,
                    0,
                    p.r * Math.abs(Math.cos(p.rot)) + p.r * 0.25,
                    p.r,
                    0,
                    0,
                    Math.PI * 2,
                );
                ctx.fill();
                ctx.fillStyle = p.pal.hot;
                ctx.globalAlpha *= 0.6;
                ctx.beginPath();
                ctx.ellipse(
                    -p.r * 0.2,
                    -p.r * 0.2,
                    p.r * 0.25,
                    p.r * 0.4,
                    0,
                    0,
                    Math.PI * 2,
                );
                ctx.fill();
            } else {
                ctx.fillStyle = p.pal.hot;
                ctx.beginPath();
                ctx.arc(0, 0, p.r * 0.5, 0, Math.PI * 2);
                ctx.fill();
            }

            ctx.restore();
        }

        const busy =
            this.state.active ||
            this.state.indeterminate ||
            this.embers.length > 0 ||
            this.sparks.length > 0 ||
            this.shocks.length > 0 ||
            Math.abs(this.state.percent - this.state.targetPct) > 0.05;

        if (busy && this.visible && !document.hidden) {
            this.raf = requestAnimationFrame((next) => this.frame(next));
        }
    }
}
