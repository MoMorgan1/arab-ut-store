// Emits the artboards for the glass control language canvas.
// Copy comes from lang/ar/store.php and lang/ar/auth_ui.php.
import fs from 'node:fs';
import path from 'node:path';
import { BASE, GLASS, TODAY, TOKENS, doc, here } from './style.mjs';

const SCREEN = `
.hero-bg{position:absolute;inset:0;background:url("background-mobile.webp") center/cover no-repeat;}
.hero-veil{position:absolute;inset:0;
  background:linear-gradient(180deg,rgb(8 7 5 / 46%),rgb(8 7 5 / 72%) 58%,rgb(8 7 5 / 94%));}
.hero{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;
  justify-content:center;gap:0;padding:28px 20px;text-align:center;}
.hero-logo{width:104px;height:104px;object-fit:contain;}
.badge{display:inline-flex;min-height:2rem;align-items:center;margin:14px 0 20px;
  border:1px solid rgb(212 168 67 / 20%);border-radius:999px;background:rgb(212 168 67 / 10%);
  padding:0.35rem 0.9rem;color:var(--gold);font-size:0.8rem;font-weight:700;}
.hero h1{line-height:1.08;text-wrap:balance;}
/* direction:ltr on the first line is lifted from app.css as it ships: it keeps
   the 27 beside "فيفا" instead of letting bidi push it to the line's edge. */
.hero h1 span{display:block;font-size:2.925rem;line-height:1.348;direction:ltr;unicode-bidi:isolate;}
.hero h1 strong{display:block;color:var(--gold);font-size:2.15rem;font-weight:inherit;
  line-height:1.174;text-shadow:0 0.75rem 2rem rgb(212 168 67 / 14%);}
.hero-sub{margin-top:16px;color:var(--muted);font-size:0.95rem;line-height:1.7;}
.hero-actions{display:flex;flex-direction:column;gap:12px;width:100%;margin-top:24px;}
.hero-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;width:100%;margin-top:28px;}
.hero-stat{display:grid;gap:4px;}
.hero-stat b{font-size:1rem;color:var(--ink);font-weight:700;}
.hero-stat i{font-style:normal;font-size:0.75rem;color:var(--muted);line-height:1.35;}

.head{display:flex;align-items:center;justify-content:space-between;gap:12px;}
.head h2{font-size:1.375rem;}
.dock{position:absolute;inset-inline:0;bottom:0;padding:14px 18px 22px;
  display:flex;flex-direction:column;gap:10px;
  background:linear-gradient(180deg,rgb(8 7 5 / 0%),rgb(8 7 5 / 82%) 30%);}
.dock__bar{display:flex;align-items:center;gap:12px;padding:12px;border-radius:26px;
  background:rgb(255 255 255 / 5%);border:1px solid rgb(255 255 255 / 12%);
  -webkit-backdrop-filter:blur(22px) saturate(170%);backdrop-filter:blur(22px) saturate(170%);
  box-shadow:inset 0 1px 0 rgb(255 255 255 / 20%),inset 0 -1px 0 rgb(0 0 0 / 30%),
    0 -8px 24px rgb(0 0 0 / 34%);}
.dock__total{display:grid;gap:2px;flex:none;}
.dock__total i{font-style:normal;font-size:0.75rem;color:var(--muted);}
.dock__total b{font-size:1.0625rem;color:var(--ink);}

.line{display:flex;align-items:center;justify-content:space-between;gap:12px;
  font-size:0.9375rem;color:var(--muted);}
.line b{color:var(--ink);font-weight:700;}
.line--total{font-size:1.0625rem;color:var(--ink);padding-top:12px;
  border-top:1px solid rgb(255 255 255 / 10%);}
.item{display:flex;gap:12px;align-items:flex-start;}
.item__art{width:56px;height:56px;border-radius:16px;flex:none;
  background:linear-gradient(150deg,rgb(212 168 67 / 26%),rgb(212 168 67 / 6%));
  border:1px solid rgb(212 168 67 / 26%);display:flex;align-items:center;justify-content:center;}
.item__body{display:grid;gap:6px;flex:1;min-width:0;}
.item__body b{font-size:0.9375rem;}
.form{display:grid;gap:14px;}
.auth-head{display:grid;gap:8px;text-align:center;margin-bottom:4px;}
.auth-head h2{font-size:1.5rem;}
.or{display:flex;align-items:center;gap:12px;color:var(--muted);font-size:0.8125rem;}
.or::before,.or::after{content:'';flex:1;height:1px;background:rgb(255 255 255 / 12%);}
.terms{text-align:center;color:var(--muted);font-size:0.75rem;line-height:1.7;}
.box{width:22px;height:22px;flex:none;border-radius:7px;
  background:var(--f-fill);border:1px solid var(--f-border);
  box-shadow:inset 0 2px 5px rgb(0 0 0 / 45%);}
/* The small controls carry their own 44px target: the visible mark stays
   small, the touchable box does not. */
.tap{display:inline-flex;align-items:center;justify-content:center;
  min-width:44px;min-height:44px;margin:-11px;flex:none;cursor:pointer;}
.field__box .tap{color:var(--muted);}
.tap--text{margin-inline:0;padding-inline:4px;}

/* The dock is the one glass surface declared here, so it needs the fallback too. */
@supports not ((backdrop-filter:blur(1px)) or (-webkit-backdrop-filter:blur(1px))){
  .dock__bar{background:rgb(28 24 20 / 96%);}
}
`;

const SHEET = `
.sheet{position:relative;width:560px;min-height:1040px;padding:32px;
  display:flex;flex-direction:column;gap:26px;
  background:url("background-mobile.webp") center/cover no-repeat,var(--navy-deep);}
.sheet__veil{position:absolute;inset:0;z-index:0;background:rgb(8 7 5 / 62%);pointer-events:none;}
/* Only the flow children lift above the veil — a blanket child rule would
   override the veil's own absolute positioning and drop it into the flow. */
.sheet > .group,.sheet > h2{position:relative;z-index:1;}
.sheet h2{font-size:1.5rem;}
.sheet h3{font-size:0.875rem;font-weight:700;color:var(--muted);
  font-family:'Thmanyah Sans',sans-serif;letter-spacing:0.02em;}
.group{display:grid;gap:12px;}
.states{display:flex;flex-wrap:wrap;gap:12px;align-items:center;}
.state{display:grid;gap:6px;justify-items:center;}
.state i{font-style:normal;font-size:0.75rem;color:var(--muted);}
`;

const check = `<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>`;
const alert = `<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.3v.2"/></svg>`;
const lock = `<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="10" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>`;
const eye = `<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/></svg>`;
const coin = `<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="#e4b756" stroke-width="1.6"><ellipse cx="12" cy="7" rx="7.5" ry="3.2"/><path d="M4.5 7v10c0 1.8 3.4 3.2 7.5 3.2s7.5-1.4 7.5-3.2V7"/><path d="M4.5 12c0 1.8 3.4 3.2 7.5 3.2s7.5-1.4 7.5-3.2"/></svg>`;
const google = `<svg viewBox="0 0 24 24" width="20" height="20"><path fill="#4285F4" d="M21.6 12.2c0-.7-.1-1.4-.2-2H12v3.8h5.4a4.6 4.6 0 0 1-2 3v2.5h3.2c1.9-1.7 3-4.3 3-7.3Z"/><path fill="#34A853" d="M12 22c2.7 0 5-.9 6.6-2.5l-3.2-2.5c-.9.6-2 1-3.4 1-2.6 0-4.8-1.7-5.6-4.1H3.1v2.6A10 10 0 0 0 12 22Z"/><path fill="#FBBC05" d="M6.4 13.9a6 6 0 0 1 0-3.8V7.5H3.1a10 10 0 0 0 0 9l3.3-2.6Z"/><path fill="#EA4335" d="M12 5.9c1.5 0 2.8.5 3.8 1.5l2.8-2.8A10 10 0 0 0 3.1 7.5l3.3 2.6C7.2 7.7 9.4 5.9 12 5.9Z"/></svg>`;

// ---- screens -------------------------------------------------------------

const hero = (today, sec = 'smoke') => {
    const b = today ? 't-btn' : 'btn';
    const s = today ? 't-btn--secondary' : `btn--${sec}`;

    return `<div dir="rtl" class="screen">
  <div class="hero-bg"></div>
  <div class="hero-veil"></div>
  <div class="hero">
    <img class="hero-logo" src="arabut-logo-hero.webp" alt="" width="104" height="104">
    <p class="badge">كل اللي تحتاجه في FC 27 بمكان واحد</p>
    <h1><span>كوينز فيفا 27</span><strong>بأفضل الأسعار</strong></h1>
    <p class="hero-sub">نوصل كوينز فيفا 27 لحسابك بسرعة وأمان — مع ضمان كامل.</p>
    <div class="hero-actions">
      <button class="${b} ${b}--primary ${b}--block" type="button"><span>اختر كوينزك</span></button>
      <button class="${b} ${s} ${b}--block" type="button"><span>استكشف خدماتنا</span></button>
    </div>
    <div class="hero-stats">
      <div class="hero-stat"><b>[العدد]</b><i>عميل خدمناهم</i></div>
      <div class="hero-stat"><b>[العدد]</b><i>طلب مكتمل</i></div>
      <div class="hero-stat"><b>+30 مليار</b><i>كوينز تم توصيلها</i></div>
      <div class="hero-stat"><b>99.9%</b><i>نسبة الأمان</i></div>
    </div>
  </div>
</div>`;
};

const coins = () => `<div dir="rtl" class="screen">
  <div class="pad">
    <div class="head">
      <div>
        <p class="eyebrow">كوينز FC 27</p>
        <h2>بيانات حسابك</h2>
      </div>
      <button class="iconbtn" type="button" aria-label="رجوع">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>
      </button>
    </div>
    <div class="tabs" role="tablist">
      <button class="tab is-on" role="tab" aria-selected="true" type="button">بلايستيشن</button>
      <button class="tab" role="tab" aria-selected="false" type="button">PC</button>
    </div>
    <div class="form">
      <div class="field">
        <label class="field__label" for="ea-mail">بريد EA الإلكتروني</label>
        <div class="field__box"><input id="ea-mail" type="email" dir="ltr" value="player@arab-ut.com"></div>
      </div>
      <div class="field field--focus">
        <label class="field__label" for="ea-pass">كلمة مرور EA</label>
        <div class="field__box">
          <input id="ea-pass" type="password" dir="ltr" value="••••••••••">
          <span class="tap">${eye}</span>
        </div>
      </div>
      <div class="field field--error">
        <label class="field__label" for="ea-balance">رصيد الكوينز الحالي</label>
        <div class="field__box"><input id="ea-balance" type="text" dir="ltr" placeholder="0"></div>
        <p class="field__note">${alert} أدخل رصيدك الحالي قبل المتابعة.</p>
      </div>
    </div>
    <div class="panel row" style="gap:10px">
      <span style="color:var(--gold-bright);display:inline-flex">${lock}</span>
      <p class="caption" style="margin:0">بياناتك محفوظة بأمان ومشفّرة.</p>
    </div>
    <div style="flex:1"></div>
    <div style="display:flex;gap:10px">
      <button class="btn btn--primary" type="button" style="flex:1"><span>أضف إلى السلة</span></button>
      <button class="btn btn--smoke" type="button"><span>رجوع</span></button>
    </div>
  </div>
</div>`;

const cart = () => `<div dir="rtl" class="screen">
  <div class="pad" style="padding-bottom:148px">
    <div class="head">
      <div>
        <p class="eyebrow">Arab UT</p>
        <h2>السلة</h2>
      </div>
      <button class="iconbtn" type="button" aria-label="حذف">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V5h6v2M7 7l1 13h8l1-13"/></svg>
      </button>
    </div>
    <div class="panel">
      <div class="item">
        <span class="item__art">${coin}</span>
        <div class="item__body">
          <b>كوينز FC 27</b>
          <p class="line"><span>المنصة</span><b>بلايستيشن</b></p>
          <p class="line"><span>التسليم</span><b>سريع</b></p>
          <p class="line"><span>كمية الكوينز</span><b>500,000</b></p>
        </div>
      </div>
    </div>
    <div class="panel" style="display:grid;gap:12px">
      <h3 style="font-size:1rem">ملخص الطلب</h3>
      <p class="line"><span>المجموع الفرعي</span><b>310.00 ر.س</b></p>
      <p class="line"><span>بيانات EA</span><b style="color:#67e89a">محفوظة بأمان</b></p>
      <p class="line line--total"><span>الإجمالي</span><b>310.00 ر.س</b></p>
    </div>
  </div>
  <div class="dock">
    <div class="dock__bar">
      <span class="dock__total"><i>الإجمالي للدفع</i><b>310.00 ر.س</b></span>
      <button class="btn btn--primary" type="button" style="flex:1"><span>${lock} المتابعة للدفع الآمن</span></button>
    </div>
  </div>
</div>`;

const login = () => `<div dir="rtl" class="screen">
  <div class="hero-bg" style="opacity:0.55"></div>
  <div class="hero-veil"></div>
  <div class="pad" style="justify-content:center">
    <div class="auth-head">
      <p class="eyebrow">عرب التيميت</p>
      <h2>تسجيل الدخول إلى حسابك</h2>
      <p class="caption">أدخل بريدك الإلكتروني وكلمة المرور للمتابعة.</p>
    </div>
    <div class="tabs" role="tablist">
      <button class="tab is-on" role="tab" aria-selected="true" type="button">البريد وكلمة المرور</button>
      <button class="tab" role="tab" aria-selected="false" type="button">الهاتف</button>
    </div>
    <div class="form">
      <div class="field">
        <label class="field__label" for="mail">البريد الإلكتروني</label>
        <div class="field__box"><input id="mail" type="email" dir="ltr" placeholder="you@example.com"></div>
      </div>
      <div class="field">
        <label class="field__label" for="pass">كلمة المرور</label>
        <div class="field__box">
          <input id="pass" type="password" dir="ltr" placeholder="••••••••">
          <span class="tap">${eye}</span>
        </div>
      </div>
      <div class="line" style="font-size:0.875rem;min-height:44px">
        <span class="row" style="gap:8px;min-height:44px"><span class="box"></span>تذكرني</span>
        <a class="tap tap--text" href="#">نسيت كلمة المرور؟</a>
      </div>
      <button class="btn btn--primary btn--block" type="button"><span>تسجيل الدخول</span></button>
      <p class="or">أو</p>
      <button class="btn btn--smoke btn--block" type="button"><span>${google} المتابعة بحساب Google</span></button>
    </div>
    <p class="terms">بالمتابعة أنت توافق على <a href="#">الشروط والأحكام</a> و<a href="#">سياسة الخصوصية</a>.</p>
  </div>
</div>`;

const sheet = (title) => `<div dir="rtl" class="sheet">
  <div class="sheet__veil"></div>
  <h2>${title}</h2>

  <div class="group">
    <h3>الزر الأساسي</h3>
    <div class="states">
      <span class="state"><button class="btn btn--primary" type="button"><span>أضف إلى السلة</span></button><i>عادي</i></span>
      <span class="state"><button class="btn btn--primary btn--hover" type="button"><span>أضف إلى السلة</span></button><i>مرور</i></span>
    </div>
    <div class="states">
      <span class="state"><button class="btn btn--primary btn--press" type="button"><span>أضف إلى السلة</span></button><i>ضغط</i></span>
      <span class="state"><button class="btn btn--primary btn--focus" type="button"><span>أضف إلى السلة</span></button><i>تركيز لوحة المفاتيح</i></span>
    </div>
    <div class="states">
      <span class="state"><button class="btn btn--disabled" type="button" disabled><span>أضف إلى السلة</span></button><i>غير متاح</i></span>
      <span class="state"><button class="btn btn--primary btn--done" type="button"><span>${check} تمت الإضافة</span></button><i>تم</i></span>
    </div>
  </div>

  <div class="group">
    <h3>الزر الثانوي — ثلاث معالجات، الأولى هي المقترحة</h3>
    <div class="states">
      <span class="state"><button class="btn btn--smoke" type="button"><span>استكشف خدماتنا</span></button><i>زجاج داكن</i></span>
      <span class="state"><button class="btn btn--quiet" type="button"><span>استكشف خدماتنا</span></button><i>خط رفيع</i></span>
    </div>
    <div class="states">
      <span class="state"><button class="btn btn--text" type="button"><span>استكشف خدماتنا</span></button><i>نص فقط</i></span>
      <span class="state"><button class="iconbtn" type="button" aria-label="التالي">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 6-6 6 6 6"/></svg>
      </button><i>زر أيقونة</i></span>
      <span class="state"><button class="btn btn--smoke" type="button"><span>${google} Google</span></button><i>جوجل</i></span>
    </div>
  </div>

  <div class="group">
    <h3>التابات — الزجاجة المختارة هي نفس عدسة الشريط السفلي</h3>
    <div class="tabs" role="tablist">
      <button class="tab is-on" role="tab" aria-selected="true" type="button">البريد</button>
      <button class="tab" role="tab" aria-selected="false" type="button">الهاتف</button>
      <button class="tab" role="tab" aria-selected="false" type="button">واتساب</button>
    </div>
  </div>

  <div class="group">
    <h3>الحقول</h3>
    <div class="field">
      <label class="field__label" for="s-mail">البريد الإلكتروني</label>
      <div class="field__box"><input id="s-mail" type="email" dir="ltr" value="player@arab-ut.com"></div>
    </div>
    <div class="field field--focus">
      <label class="field__label" for="s-pass">كلمة مرور EA</label>
      <div class="field__box">
        <input id="s-pass" type="password" dir="ltr" value="••••••••••">
        <span class="tap">${eye}</span>
      </div>
      <p class="caption" style="color:var(--muted)">حالة التركيز</p>
    </div>
    <div class="field field--error">
      <label class="field__label" for="s-bal">رصيد الكوينز الحالي</label>
      <div class="field__box"><input id="s-bal" type="text" dir="ltr" placeholder="0"></div>
      <p class="field__note">${alert} أدخل رصيدك الحالي قبل المتابعة.</p>
    </div>
  </div>
</div>`;

// ---- emit ----------------------------------------------------------------

const glassStyles = (dir) => [BASE, TOKENS[dir], GLASS, SCREEN].join('\n');
const sheetStyles = (dir) =>
    [BASE, TOKENS[dir], GLASS, SCREEN, SHEET].join('\n');

const files = {
    'Current.dc.html': doc(
        [BASE, TOKENS.A, GLASS, SCREEN, TODAY].join('\n'),
        hero(true),
    ),
    'Main.dc.html': doc(glassStyles('A'), hero(false, 'smoke')),
    'CoinsA.dc.html': doc(glassStyles('A'), coins()),
    'CartA.dc.html': doc(glassStyles('A'), cart()),
    'LoginA.dc.html': doc(glassStyles('A'), login()),
    'SheetA.dc.html': doc(
        sheetStyles('A'),
        sheet('الاتجاه أ — زجاج مضاء بالذهب'),
    ),
};

for (const [name, source] of Object.entries(files)) {
    fs.writeFileSync(path.join(here, name), source, 'utf8');
}

for (const stale of [
    'DirectionB.dc.html',
    'HeroQuiet.dc.html',
    'HeroText.dc.html',
    'CoinsB.dc.html',
    'CartB.dc.html',
    'LoginB.dc.html',
    'SheetB.dc.html',
]) {
    fs.rmSync(path.join(here, stale), { force: true });
}

const canvas = {
    artboards: [
        {
            file: 'Main.dc.html',
            title: 'الهيرو',
            x: 0,
            y: 0,
            w: 390,
            h: 844,
            page: 'page-1',
        },
        {
            file: 'Current.dc.html',
            title: 'الحالي — قبل',
            x: 480,
            y: 0,
            w: 390,
            h: 844,
            page: 'page-1',
        },
        {
            file: 'CoinsA.dc.html',
            title: 'بيانات الحساب',
            x: 0,
            y: 0,
            w: 390,
            h: 844,
            page: 'page-2',
        },
        {
            file: 'CartA.dc.html',
            title: 'السلة والدفع',
            x: 480,
            y: 0,
            w: 390,
            h: 844,
            page: 'page-2',
        },
        {
            file: 'LoginA.dc.html',
            title: 'تسجيل الدخول',
            x: 960,
            y: 0,
            w: 390,
            h: 844,
            page: 'page-2',
        },
        {
            file: 'SheetA.dc.html',
            title: 'العناصر والحالات',
            x: 1440,
            y: 0,
            w: 560,
            h: 1040,
            page: 'page-2',
        },
    ],
    annotations: [
        {
            id: 'brief',
            x: 0,
            y: -250,
            w: 900,
            text:
                'المعتمد والمنفّذ: أساسي زجاج مضاء بالذهب، وثانوي زجاج داكن بلا ذهب.\n' +
                'الفرق ليس في الإضاءة: الثانوي مادة أخرى، لا نسخة باهتة من الأساسي.\n' +
                'الكود في resources/css/app.css تحت عنوان Control language (2026-09-14).',
        },
        {
            id: 'caveat',
            x: 960,
            y: -250,
            w: 440,
            text:
                'ملاحظة من HIG آبل: الزجاج لطبقة التحكم، لا لطبقة المحتوى، وبقلة.\n' +
                'لذلك كروت السلة والمحتوى بقيت أسطحًا صلبة كما هي اليوم، والزجاج على الأزرار والحقول والتابات ودوك الدفع فقط.',
        },
        {
            id: 'lens',
            x: 0,
            y: -250,
            w: 440,
            page: 'page-2',
            text:
                'التاب المختار هو نفس عدسة الشريط السفلي الموجودة اليوم: تكبّر وتضيء ما خلفها بدل ما تدهن لون فوقه.\n' +
                'هذا اللي يربط اللغة الجديدة بالموجود بدل ما يستبدله.',
        },
    ],
    pages: [
        { id: 'page-1', name: 'الهيرو' },
        { id: 'page-2', name: 'بقية الشاشات' },
    ],
    launch: { view: 'canvas', page: 'page-1' },
};

fs.writeFileSync(
    path.join(here, 'canvas.json'),
    JSON.stringify(canvas, null, 2),
    'utf8',
);
console.log('artboards:', Object.keys(files).length);
