// Run: node plugins-src/sebo-attribution/tests/capture.test.js
// Loads assets/capture.js against a fake browser for each scenario and checks
// the touch it records. No dependencies.
const fs = require('fs'), vm = require('vm');
const src = fs.readFileSync(require('path').join(__dirname, '..', 'assets', 'capture.js'), 'utf8');
function load(url, referrer, cookie = '', cfg = {}) {
  const u = new URL(url);
  let jar = cookie; const fetched = [];
  const document = { referrer, addEventListener() {},
    get cookie() { return jar; },
    set cookie(v) { const kv = v.split(';')[0]; jar = kv; } };
  const window = { location: { search: u.search, hostname: u.hostname, href: u.href, protocol: u.protocol },
    seboAttribution: Object.assign({ refreshUrl: '/r' }, cfg), fetch: (x) => { fetched.push(x); return Promise.resolve(); } };
  vm.runInNewContext(src, { window, document, URL, Date, JSON, Math, encodeURIComponent, decodeURIComponent, RegExp, parseInt, String });
  const m = jar.match(/sebo_attr=(.*)/);
  return { data: m ? JSON.parse(decodeURIComponent(m[1])) : null, jar, fetched };
}
let fail = 0;
function check(name, got, want) { const ok = JSON.stringify(got) === JSON.stringify(want); if (!ok) fail++; console.log((ok ? 'ok   ' : 'FAIL ') + name + (ok ? '' : `  got ${JSON.stringify(got)} want ${JSON.stringify(want)}`)); }
const L = d => [d.last.channel, d.last.source, d.last.medium];

let r = load('https://site.test/?gclid=abc', '');
check('gclid, no UTM -> Paid Search google/cpc', L(r.data), ['Paid Search','google','cpc']);
check('gclid stored', r.data.ids.gclid.v, 'abc');
check('refresh called after write', r.fetched.length, 1);
r = load('https://site.test/?gclid=abc&utm_source=google&utm_medium=cpc&utm_campaign=Spring', 'https://www.google.com/');
check('gclid + UTMs', [...L(r.data), r.data.last.campaign], ['Paid Search','google','cpc','Spring']);
r = load('https://site.test/?gbraid=xyz', '');
check('gbraid -> Paid Search', L(r.data), ['Paid Search','google','cpc']);
r = load('https://site.test/?msclkid=m1', 'https://www.bing.com/');
check('msclkid -> Paid Search bing', L(r.data), ['Paid Search','bing','cpc']);
r = load('https://site.test/page', 'https://www.google.com/');
check('google referrer -> Organic Search', L(r.data), ['Organic Search','google','organic']);
r = load('https://site.test/page', 'https://duckduckgo.com/');
check('ddg -> Organic Search', L(r.data), ['Organic Search','duckduckgo','organic']);
r = load('https://site.test/?fbclid=f1', 'https://l.facebook.com/');
check('fbclid w/ facebook referrer -> Organic Social', L(r.data), ['Organic Social','facebook','social']);
r = load('https://site.test/?fbclid=f1', '');
check('fbclid no referrer -> Organic Social', L(r.data), ['Organic Social','facebook','social']);
r = load('https://site.test/?utm_source=facebook&utm_medium=paid_social&fbclid=f1', '');
check('facebook paid -> Paid Social', L(r.data), ['Paid Social','facebook','paid_social']);
r = load('https://site.test/?utm_source=newsletter&utm_medium=email', '');
check('email', L(r.data)[0], 'Email');
r = load('https://site.test/?UTM_Source=Mailchimp&UTM_Medium=Email', '');
check('uppercase UTM keys', L(r.data), ['Email','Mailchimp','Email']);
r = load('https://site.test/?utm_source=yelp&utm_medium=qr', '');
check('unknown medium -> Other Campaign', L(r.data)[0], 'Other Campaign');
r = load('https://site.test/?utm_source=partner&utm_medium=display', '');
check('display', L(r.data)[0], 'Display');
r = load('https://site.test/?utm_source=bing&utm_medium=cpc', '');
check('bing cpc without click id', L(r.data)[0], 'Paid Search');
r = load('https://site.test/?utm_source=bigboxpartner&utm_medium=cpc', '');
check('cpc from non-search -> Paid Other', L(r.data)[0], 'Paid Other');
r = load('https://site.test/', 'https://www.linkedin.com/feed');
check('linkedin -> Organic Social', L(r.data), ['Organic Social','linkedin','social']);
r = load('https://site.test/', 'https://someblog.example/post');
check('referral', [...L(r.data), r.data.last.referrer], ['Referral','someblog.example','referral','https://someblog.example/post']);
r = load('https://site.test/', '');
check('direct first visit', L(r.data), ['Direct','(direct)','(none)']);
r = load('https://www.site.test/about', 'https://site.test/');
check('internal nav (www vs bare) -> no cookie', r.data, null);

// Sequences
let jar = load('https://site.test/?gclid=G1&utm_campaign=Roofs', '').jar;
let r2 = load('https://site.test/', '', jar);
check('ad then direct: last stays Paid Search', r2.data.last.channel, 'Paid Search');
check('ad then direct: no rewrite, no refresh', r2.fetched.length, 0);
let r3 = load('https://site.test/', 'https://www.google.com/', jar);
check('ad then organic: last = Organic Search', r3.data.last.channel, 'Organic Search');
check('ad then organic: first stays Paid Search', r3.data.first.channel, 'Paid Search');
check('ad then organic: gclid kept', r3.data.ids.gclid.v, 'G1');
let r4 = load('https://site.test/contact', 'https://site.test/', r3.jar);
check('internal nav keeps data', r4.data.last.channel, 'Organic Search');
jar = load('https://site.test/', '').jar;
r2 = load('https://site.test/?utm_source=google&utm_medium=cpc', '', jar);
check('direct then ad: last = Paid Search, first = Direct', [r2.data.first.channel, r2.data.last.channel], ['Direct','Paid Search']);
r = load('https://site.test/?gclid=abc', '', '', { requireConsent: true });
check('consent required, none given -> nothing written', r.data, null);
r = load('https://site.test/?utm_source=x&utm_campaign=' + 'a'.repeat(1000), '');
check('long values capped', r.data.last.campaign.length, 150);
check('cookie under 4KB', r.jar.length < 4000, true);
console.log(fail ? `\n${fail} FAILED` : '\nall passed');
process.exit(fail ? 1 : 0);
