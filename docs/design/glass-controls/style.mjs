// Shared material for the glass control language artboards.
// Values are lifted from resources/js/styles/tokens.css and
// resources/css/app.css so the mockups match the real store.
import fs from 'node:fs';
import path from 'node:path';

export const here = path
    .dirname(new URL(import.meta.url).pathname)
    .replace(/^\/([A-Za-z]:)/, '$1');
export const repo = path.resolve(here, '../../..');
const fontDir = path.join(repo, 'public/fonts/thmanyah');

const face = (family, weight, file) =>
    `@font-face{font-family:'${family}';font-style:normal;font-weight:${weight};font-display:block;` +
    `src:url(data:font/woff2;base64,${fs
        .readFileSync(path.join(fontDir, file))
        .toString('base64')}) format('woff2');}`;

export const FONTS = [
    face('Thmanyah Sans', 500, 'thmanyahsans-Medium.woff2'),
    face('Thmanyah Sans', 700, 'thmanyahsans-Bold.woff2'),
    face('Thmanyah Serif Display', 700, 'thmanyahserifdisplay-Bold.woff2'),
].join('\n');

// The store's own palette.
export const BASE = `
:root{
  --navy-deep:#080705; --navy:#0d0b08; --navy-raised:#1c1814; --navy-active:#241e18;
  --ink:#ede4d0; --muted:#a89880; --gold:#d4a843; --gold-bright:#e4b756;
  --line:rgb(212 168 67 / 16%);
  --ease:cubic-bezier(0.16,1,0.3,1);
}
*{box-sizing:border-box;}
body{margin:0;background:var(--navy-deep);
  font-family:'Thmanyah Sans',Tahoma,Arial,sans-serif;color:var(--ink);
  -webkit-font-smoothing:antialiased;}
a{color:var(--gold-bright);text-decoration:none;}
a:hover{color:#f3d489;}
h1,h2,h3{font-family:'Thmanyah Serif Display','Thmanyah Sans',serif;margin:0;font-weight:700;}
p{margin:0;}
.screen{position:relative;width:390px;height:844px;overflow:hidden;background:var(--navy-deep);}
.pad{position:absolute;inset:0;display:flex;flex-direction:column;gap:18px;padding:22px 18px;}
.eyebrow{color:var(--muted);font-size:0.8125rem;font-weight:500;}
.caption{color:var(--muted);font-size:0.8125rem;line-height:1.6;}
.row{display:flex;align-items:center;gap:10px;}
`;

// One material for every control. The bevel is the anatomy in the reference:
// a bright rim on the top-left edge, shade on the bottom-right, and one
// diagonal specular band across the body. What separates the primary from the
// secondary is the fill and the label colour, never a second bevel.
export const GLASS = `
.btn{
  position:relative;isolation:isolate;
  display:inline-flex;align-items:center;justify-content:center;gap:8px;
  min-height:50px;padding:0 22px;border-radius:999px;
  font-family:inherit;font-size:1.0625rem;font-weight:700;line-height:1;
  cursor:pointer;overflow:hidden;
  -webkit-backdrop-filter:blur(20px) saturate(180%) brightness(1.12);
  backdrop-filter:blur(20px) saturate(180%) brightness(1.12);
  transition:transform 180ms var(--ease),box-shadow 180ms var(--ease),
    background-color 160ms ease,border-color 160ms ease;
}
.btn::before{content:'';position:absolute;inset:0;border-radius:inherit;pointer-events:none;z-index:1;
  background:linear-gradient(152deg,rgb(255 255 255 / 30%),rgb(255 255 255 / 4%) 26%,
    transparent 46%,transparent 66%,rgb(255 255 255 / 8%) 100%);}
.btn > span{position:relative;z-index:2;display:inline-flex;align-items:center;gap:8px;}
.btn--primary{background:var(--p-fill);border:1px solid var(--p-border);color:var(--p-ink);
  box-shadow:
    inset 1px 1px 0 var(--p-rim),
    inset 2px 2px 7px rgb(255 255 255 / 14%),
    inset -1px -1px 0 rgb(0 0 0 / 30%),
    inset -2px -3px 9px rgb(0 0 0 / 24%),
    var(--p-glow);}
.btn--secondary{background:var(--s-fill);border:1px solid var(--s-border);color:var(--s-ink);
  box-shadow:
    inset 1px 1px 0 rgb(255 255 255 / 34%),
    inset -1px -1px 0 rgb(0 0 0 / 26%),
    0 8px 20px -14px rgb(0 0 0 / 70%);}

/* The bevel above is deliberately PHYSICAL, not logical: a light source does
   not mirror when the text does, so the rim stays top-left and the shade
   bottom-right in Arabic exactly as in English. */

/* Three ways for the secondary to stay subordinate to the lit primary.
   Smoke is a different material, not a dimmer copy: dark glass, no gold.
   Quiet keeps only the hairline. Text drops the object altogether.
   A text button has no box, so it carries its own 44px tap target. */
.btn--smoke{background:linear-gradient(152deg,rgb(22 18 13 / 66%),rgb(8 7 5 / 50%));
  border:1px solid rgb(237 228 208 / 16%);color:var(--ink);font-weight:700;
  box-shadow:
    inset 1px 1px 0 rgb(255 255 255 / 16%),
    inset -1px -1px 0 rgb(0 0 0 / 42%),
    0 8px 20px -14px rgb(0 0 0 / 80%);}
.btn--smoke::before{opacity:0.4;}
.btn--quiet{background:transparent;border:1px solid rgb(212 168 67 / 28%);color:var(--ink);
  font-weight:500;box-shadow:none;
  -webkit-backdrop-filter:blur(10px) saturate(140%);backdrop-filter:blur(10px) saturate(140%);}
.btn--quiet::before{opacity:0.3;}
.btn--text{background:transparent;border:0;color:var(--gold-bright);font-weight:700;
  box-shadow:none;-webkit-backdrop-filter:none;backdrop-filter:none;}
.btn--text::before{display:none;}
.btn--text:hover{color:#f3d489;}
.btn--block{width:100%;}
.btn--hover.btn--primary{background:var(--p-fill-hover);border-color:var(--p-border-hover);
  transform:translateY(-1px);
  box-shadow:
    inset 1px 1px 0 var(--p-rim),
    inset 2px 2px 8px rgb(255 255 255 / 18%),
    inset -1px -1px 0 rgb(0 0 0 / 30%),
    inset -2px -3px 9px rgb(0 0 0 / 24%),
    var(--p-glow-hover);}
.btn--press.btn--primary{transform:translateY(1px);
  box-shadow:
    inset 2px 3px 10px rgb(0 0 0 / 42%),
    inset -1px -1px 0 rgb(255 255 255 / 12%),
    0 3px 10px -8px rgb(0 0 0 / 70%);}
.btn--disabled{background:rgb(255 255 255 / 4%);border:1px solid rgb(255 255 255 / 10%);
  color:rgb(237 228 208 / 38%);box-shadow:inset 0 1px 0 rgb(255 255 255 / 8%);
  cursor:not-allowed;-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px);}
.btn--disabled::before{opacity:0.35;}
.btn--focus{outline:2px solid var(--gold-bright);outline-offset:3px;}
.btn--done{background:rgb(103 232 154 / 12%);border-color:rgb(103 232 154 / 55%);
  color:#67e89a;box-shadow:inset 1px 1px 0 rgb(255 255 255 / 22%),0 0 0 4px rgb(103 232 154 / 10%);}

/* Fields read as pressed into the glass, never floating on it. */
.field{display:grid;gap:8px;}
.field__label{font-size:0.9375rem;font-weight:700;color:var(--f-label);}
.field__box{position:relative;display:flex;align-items:center;gap:10px;
  min-height:50px;padding:0 14px;border-radius:16px;
  background:var(--f-fill);border:1px solid var(--f-border);
  -webkit-backdrop-filter:blur(14px) saturate(150%);backdrop-filter:blur(14px) saturate(150%);
  box-shadow:inset 0 2px 6px rgb(0 0 0 / 48%),inset 0 -1px 0 rgb(255 255 255 / 7%);}
.field__box input{flex:1;min-width:0;border:0;background:transparent;outline:0;
  font:inherit;font-size:1rem;color:var(--ink);padding:14px 0;}
.field__box input::placeholder{color:#b9a98e;}
.field--focus .field__box{border-color:var(--f-focus-border);
  box-shadow:inset 0 2px 6px rgb(0 0 0 / 48%),0 0 0 3px var(--f-focus-ring);}
.field--error .field__box{border-color:#ffb4a9;
  box-shadow:inset 0 2px 6px rgb(0 0 0 / 48%),0 0 0 3px rgb(255 180 169 / 18%);}
.field__note{display:flex;align-items:center;gap:6px;color:#ffb4a9;font-size:0.8125rem;}

/* Segmented tabs. The selected tab is the same lens the bottom bar already
   uses: it magnifies and brightens what is behind it instead of painting a
   colour over it. */
.tabs{display:flex;gap:4px;padding:4px;border-radius:18px;
  background:rgb(255 255 255 / 5%);border:1px solid var(--tabs-border);
  -webkit-backdrop-filter:blur(18px) saturate(160%);backdrop-filter:blur(18px) saturate(160%);
  box-shadow:inset 0 1px 0 rgb(255 255 255 / 12%),inset 0 -1px 0 rgb(0 0 0 / 22%);}
.tab{flex:1;min-height:44px;border:0;border-radius:14px;background:transparent;
  font:inherit;font-size:0.9375rem;font-weight:700;color:var(--muted);cursor:pointer;}
.tab.is-on{color:var(--tab-on-ink);
  background:linear-gradient(160deg,rgb(255 255 255 / 28%),rgb(255 255 255 / 9%) 46%,var(--lens-warm));
  -webkit-backdrop-filter:blur(1px) saturate(190%) brightness(1.35);
  backdrop-filter:blur(1px) saturate(190%) brightness(1.35);
  box-shadow:
    inset 0 1px 0 rgb(255 255 255 / 55%),
    inset 0 -1px 0 var(--lens-rim),
    inset 1px 0 0 rgb(255 214 170 / 12%),
    inset -1px 0 0 rgb(170 214 255 / 10%),
    0 6px 18px rgb(0 0 0 / 24%);}

.iconbtn{display:inline-flex;align-items:center;justify-content:center;
  width:44px;height:44px;border-radius:999px;cursor:pointer;flex:none;
  background:var(--s-fill);border:1px solid var(--s-border);color:var(--ink);
  -webkit-backdrop-filter:blur(18px) saturate(170%);backdrop-filter:blur(18px) saturate(170%);
  box-shadow:inset 1px 1px 0 rgb(255 255 255 / 30%),inset -1px -1px 0 rgb(0 0 0 / 26%);}

/* Content cards stay flat on purpose: the HIG keeps this material out of the
   content layer, so the card is a surface and the controls on it are the glass. */
.panel{border-radius:22px;padding:16px;
  background:var(--navy-raised);border:1px solid rgb(255 255 255 / 8%);
  box-shadow:inset 0 1px 0 rgb(255 255 255 / 6%),0 10px 30px -20px rgb(0 0 0 / 90%);}

/* Without backdrop-filter the glass becomes an honest warm surface. */
@supports not ((backdrop-filter:blur(1px)) or (-webkit-backdrop-filter:blur(1px))){
  .btn--primary{background:var(--p-solid);}
  .btn--secondary,.btn--smoke,.btn--quiet,.iconbtn{background:rgb(28 24 20 / 96%);}
  .field__box{background:var(--navy-active);}
  .tabs{background:rgb(13 11 8 / 96%);}
  .tab.is-on{background:rgb(36 30 24 / 98%);}
}
`;

export const TOKENS = {
    A: `
:root{
  --p-fill:linear-gradient(152deg,rgb(246 214 140 / 42%),rgb(212 168 67 / 20%) 46%,rgb(176 128 32 / 28%));
  --p-fill-hover:linear-gradient(152deg,rgb(250 224 158 / 52%),rgb(224 182 84 / 26%) 46%,rgb(190 140 38 / 34%));
  --p-border:rgb(244 212 132 / 62%); --p-border-hover:rgb(248 220 148 / 82%);
  --p-ink:#fdf3dc; --p-rim:rgb(255 244 216 / 66%);
  --p-glow:0 16px 34px -14px rgb(212 168 67 / 72%);
  --p-glow-hover:0 20px 40px -14px rgb(212 168 67 / 88%);
  --p-solid:linear-gradient(180deg,#5a4418,#3b2c0f);
  --s-fill:linear-gradient(152deg,rgb(255 255 255 / 10%),rgb(255 255 255 / 3%) 50%,rgb(212 168 67 / 7%));
  --s-border:rgb(212 168 67 / 34%); --s-ink:#ede4d0;
  --f-fill:linear-gradient(180deg,rgb(8 7 5 / 58%),rgb(14 11 8 / 44%));
  --f-border:rgb(212 168 67 / 22%); --f-focus-border:#e4b756;
  --f-focus-ring:rgb(212 168 67 / 20%); --f-label:#ede4d0;
  --tabs-border:rgb(212 168 67 / 24%); --tab-on-ink:#f8e6bb;
  --lens-warm:rgb(212 168 67 / 24%); --lens-rim:rgb(212 168 67 / 30%);
}`,
    B: `
:root{
  --p-fill:linear-gradient(152deg,rgb(255 255 255 / 16%),rgb(255 255 255 / 5%) 48%,rgb(255 255 255 / 10%));
  --p-fill-hover:linear-gradient(152deg,rgb(255 255 255 / 22%),rgb(255 255 255 / 8%) 48%,rgb(255 255 255 / 14%));
  --p-border:rgb(255 255 255 / 28%); --p-border-hover:rgb(255 255 255 / 44%);
  --p-ink:#ecc36b; --p-rim:rgb(255 255 255 / 55%);
  --p-glow:0 14px 30px -14px rgb(0 0 0 / 75%);
  --p-glow-hover:0 18px 36px -14px rgb(0 0 0 / 85%);
  --p-solid:linear-gradient(180deg,#2a2520,#1a1611);
  --s-fill:linear-gradient(152deg,rgb(255 255 255 / 8%),rgb(255 255 255 / 2.5%) 50%,rgb(255 255 255 / 5%));
  --s-border:rgb(255 255 255 / 18%); --s-ink:#ede4d0;
  --f-fill:linear-gradient(180deg,rgb(255 255 255 / 9%),rgb(255 255 255 / 5%));
  --f-border:rgb(255 255 255 / 18%); --f-focus-border:rgb(255 255 255 / 55%);
  --f-focus-ring:rgb(255 255 255 / 14%); --f-label:#ede4d0;
  --tabs-border:rgb(255 255 255 / 14%); --tab-on-ink:#ede4d0;
  --lens-warm:rgb(255 255 255 / 8%); --lens-rim:rgb(255 255 255 / 20%);
}`,
};

// Today's shipped language, for the reference artboard.
export const TODAY = `
.t-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;
  min-height:50px;padding:0 22px;border-radius:0.75rem;font:inherit;font-size:1.0625rem;
  font-weight:700;line-height:1;cursor:pointer;}
.t-btn--primary{border:1px solid rgb(240 202 112 / 72%);color:#080705;
  background:linear-gradient(180deg,#f0ca70 0%,#d4a843 56%,#b88725 100%);
  box-shadow:inset 0 1px 0 rgb(255 255 255 / 38%),inset 0 -1px 0 rgb(0 0 0 / 18%),
    0 10px 24px -12px rgb(212 168 67 / 80%);}
.t-btn--secondary{border:1px solid rgb(212 168 67 / 38%);color:var(--ink);
  background:rgb(255 255 255 / 3.5%);font-weight:700;
  box-shadow:inset 0 1px 0 rgb(255 255 255 / 6%);}
.t-btn--block{width:100%;}
.t-field{display:grid;gap:8px;}
.t-field label{font-size:0.9375rem;font-weight:700;color:var(--ink);}
.t-box{display:flex;align-items:center;min-height:48px;padding:0 13px;
  border:1px solid rgb(255 255 255 / 14%);border-radius:0.65rem;background:var(--navy-active);}
.t-box input{flex:1;min-width:0;border:0;background:transparent;outline:0;font:inherit;
  color:var(--ink);padding:11px 0;}
.t-tabs{display:flex;gap:0.25rem;padding:0.25rem;border-radius:0.9rem;
  border:1px solid rgb(212 168 67 / 20%);background:rgb(8 8 8 / 45%);
  box-shadow:inset 0 1px 0 rgb(255 255 255 / 4%);}
.t-tab{flex:1;min-height:2.625rem;border:0;border-radius:0.65rem;background:transparent;
  font:inherit;font-weight:700;color:var(--muted);cursor:pointer;}
.t-tab.is-on{background:linear-gradient(180deg,#f0ca70 0%,#d4a843 56%,#b88725 100%);color:#080705;
  box-shadow:inset 0 1px 0 rgb(255 255 255 / 38%),0 8px 18px -10px rgb(212 168 67 / 80%);}
`;

export const doc = (styles, body) => `<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <script src="./support.js"></script>
</head>
<body>
<x-dc>
<helmet>
  <style>
${FONTS}
${styles}
  </style>
</helmet>
${body}
</x-dc>
</body>
</html>
`;
