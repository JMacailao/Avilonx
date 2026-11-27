// AvilonX PHP: checks of the API against an empty database (tests/run.sh
// creates it): first start, accounts, access rules between roles and clubs,
// entries, clocking, loft readers, results, race point moves, password reset.
// Leaves test data that tests/smoke.cjs then uses.
const BASE = process.env.BASE || 'http://127.0.0.1:8090';
const failures = [];
const check = (ok, message) => { if (!ok) failures.push(message); };

function client() {
  let cookie = '';
  const call = async (route, body = {}) => {
    const res = await fetch(`${BASE}/api/index.php?r=${encodeURIComponent(route)}`, {
      method: 'POST', headers: { 'Content-Type': 'application/json', Cookie: cookie }, body: JSON.stringify(body),
    });
    const set = res.headers.get('set-cookie');
    if (set) cookie = set.split(';')[0];
    return res.json();
  };
  const form = async (route, data) => {
    const res = await fetch(`${BASE}/api/index.php?r=${encodeURIComponent(route)}`, { method: 'POST', headers: { Cookie: cookie }, body: data });
    return res.json();
  };
  return {
    call, form,
    rpc: (name, args = {}) => call(`rpc/${name}`, args),
    login: (username, password) => call('auth/login', { username, password }),
    q: (table, op, extra = {}) => call('query', { table, op, columns: '*', filters: [], order: [], ...extra }),
  };
}

(async () => {
  const visitor = client(), sup = client(), admin = client(), staff = client(), member = client(), other = client(), reader = client();

  // First start: the Super Admin account, once.
  check((await visitor.rpc('has_admin_account')).data === false, 'new database: no Super Admin yet');
  check((await visitor.rpc('create_superadmin', { p_username: 'super', p_password: 'superpass1' })).data?.status === 'created', 'Super Admin created');
  check((await visitor.rpc('create_superadmin', { p_username: 'super2', p_password: 'superpass1' })).error?.code === '42501', 'only one Super Admin');
  check((await sup.login('super', 'wrongpass')).error, 'wrong password refused');
  check((await sup.login('super', 'superpass1')).data?.session, 'Super Admin signs in');
  check((await visitor.q('clubs', 'select')).error?.code === '42501', 'not signed in: no table access');

  // Clubs and their administrators.
  check((await sup.rpc('superadmin_create_club', { p_club_name: 'Mayma Club', p_username: 'admin', p_password: 'adminpass1' })).data?.club_id === 1, 'club 1 created');
  await sup.rpc('superadmin_create_club', { p_club_name: 'Other Club', p_username: 'otheradmin', p_password: 'otherpass1' });
  check((await sup.rpc('superadmin_create_club', { p_club_name: 'x', p_username: 'admin', p_password: 'adminpass1' })).error, 'short club name refused');
  await admin.login('admin', 'adminpass1'); await other.login('otheradmin', 'otherpass1');
  check((await admin.rpc('superadmin_create_club', { p_club_name: 'Hack', p_username: 'hack', p_password: 'hackpass1' })).error?.code === '42501', 'club admin cannot create clubs');

  // Super Admin: a tag and stickers.
  check((await sup.rpc('superadmin_register_nfc_tag', { p_tag: '04:a2:3b:1c' })).data === '04A23B1C', 'tag registered (normalized)');
  check((await sup.rpc('superadmin_register_nfc_tag', { p_tag: '04A23B1C' })).error?.code === '23505', 'tag registered once');
  check((await sup.rpc('superadmin_register_sticker', { p_barcode_number: '00000126', p_scratch_code: '482715' })).data === '00000126', 'sticker registered');
  const batch = await sup.rpc('superadmin_generate_sticker_batch', { p_quantity: 3, p_register: true });
  check(batch.data?.length === 3 && /^\d{8}$/.test(batch.data[0].barcode_number) && /^\d{6}$/.test(batch.data[0].scratch_code), 'sticker batch generated');
  const stickers = await admin.q('barcode_stickers', 'select');
  check(stickers.data?.length === 4 && stickers.data.every(s => !('scratch_code' in s)), 'secret codes are never sent to the pages');
  check((await admin.q('barcode_stickers', 'select', { filters: [['scratch_code', 'eq', '482715']] })).error?.code === '42703', 'secret codes cannot be guessed with a filter');

  // Club data: members, race point, race.
  const m = await admin.q('members', 'insert', { values: { member_no: 'M-01', full_name: 'Ana Cruz', loft_name: 'Sky Loft', latitude: 14.1, longitude: 121.1, paid: 1 }, returning: 'id', single: 'single' });
  check(m.data?.id === 1, 'member added');
  await admin.q('race_points', 'insert', { values: { location: 'Batangas', sector: 'South', latitude: 13.75, longitude: 121.05 } });
  const race = await admin.q('races', 'insert', { values: { name: 'Derby', race_point_id: 1, release_location: 'Batangas', release_latitude: 13.75, release_longitude: 121.05 }, returning: 'id,status,club_id', single: 'single' });
  check(race.data?.status === 'draft' && race.data.club_id === 1, 'race added to the admin\'s club');
  check((await admin.q('races', 'update', { values: { status: 'open' }, filters: [['id', 'eq', 1]] })).error?.code === '23514', 'race cannot open without a release time');
  const release = new Date(Date.now() - 3 * 3600e3).toISOString();
  await admin.q('races', 'update', { values: { status: 'open', release_at_utc: release }, filters: [['id', 'eq', 1]] });

  // Other club sees none of it, and cannot change it.
  check((await other.q('members', 'select')).data?.length === 0, 'other club: no members of club 1');
  check((await other.q('races', 'select')).data?.length === 0, 'other club: no races of club 1');
  await other.q('races', 'update', { values: { name: 'Hacked' }, filters: [['id', 'eq', 1]] });
  check((await admin.q('races', 'select', { filters: [['id', 'eq', 1]], single: 'single' })).data?.name === 'Derby', 'other club cannot rename the race');
  check((await other.q('members', 'insert', { values: { member_no: 'X', full_name: 'X', loft_name: 'X', latitude: 1, longitude: 1, club_id: 1 } })).error?.code === '42501', 'other club cannot add members to club 1');

  // Bird bonding with a photo, and the race entry.
  const photo = new Blob([Buffer.from('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=', 'base64')], { type: 'image/jpeg' });
  const upload = (c, path) => { const f = new FormData(); f.append('bucket', 'pigeon-photos'); f.append('path', path); f.append('content_type', 'image/jpeg'); f.append('file', photo, 'b.jpg'); return c.form('storage/upload', f); };
  check((await upload(admin, '1/bird1.jpg')).data?.path === '1/bird1.jpg', 'bird photo uploaded');
  check((await upload(other, '1/evil.jpg')).error?.code === '42501', 'other club cannot upload into club 1');
  const signed = await admin.call('storage/sign', { bucket: 'pigeon-photos', paths: ['1/bird1.jpg'] });
  const file = await fetch(`${BASE}/api/index.php?r=storage/file&bucket=pigeon-photos&path=1/bird1.jpg&token=${signed.data?.[0]?.token}`);
  check(file.status === 200 && file.headers.get('content-type') === 'image/jpeg', 'signed photo link opens');
  check((await fetch(`${BASE}/api/index.php?r=storage/file&bucket=pigeon-photos&path=1/bird1.jpg`)).status === 404, 'photo without a signed link: not found');
  const bird = await admin.q('pigeons', 'insert', { values: { member_id: 1, ring_number: ' ph-1 ', bird_name: 'Blue', color: 'Blue bar', loft_name: 'Sky Loft', nfc_tag: '04a23b1c', photo_path: '1/bird1.jpg' }, returning: 'id,ring_number,nfc_tag', single: 'single' });
  check(bird.data?.ring_number === 'PH-1' && bird.data.nfc_tag === '04A23B1C', 'bird bonded (ring upper case, tag normalized)');
  check((await admin.q('pigeons', 'insert', { values: { member_id: 1, ring_number: 'PH-9', bird_name: 'X', color: 'X', loft_name: 'X', nfc_tag: 'DEADBEEF', photo_path: '1/x.jpg' } })).error?.message.includes('not registered'), 'unregistered tag refused');
  check((await admin.q('race_entries', 'insert', { values: { race_id: 1, pigeon_id: 1, distance_m: 1000 } })).error?.message.includes('requires the sticker'), 'club rule: sticker required');
  const entry = await admin.q('race_entries', 'insert', { values: { race_id: 1, pigeon_id: 1, barcode: '00000126', distance_m: 12345.678, remarks: ['P1'] }, returning: 'id,member_id,pigeon_ring,nfc_tag,loft_latitude,remarks', single: 'single' });
  check(entry.data?.pigeon_ring === 'PH-1' && entry.data.nfc_tag === '04A23B1C' && entry.data.loft_latitude === 14.1 && entry.data.remarks[0] === 'P1', 'entry filled from the bonded bird');

  // Accounts: member login, staff cannot manage accounts.
  check((await admin.rpc('admin_create_member_account', { p_member_id: 1, p_username: 'ana', p_password: 'anapass12' })).data?.status === 'created', 'member login created');
  check((await admin.rpc('admin_create_team_account', { p_username: 'staff1', p_password: 'staffpass1', p_role: 'staff' })).data?.status === 'created', 'staff account created');
  await staff.login('staff1', 'staffpass1');
  check((await staff.rpc('admin_create_member_account', { p_member_id: 1, p_username: 'x1x', p_password: 'xxxxxxxx' })).error?.code === '42501', 'staff cannot create logins');
  check((await staff.q('members', 'select')).data?.length === 1, 'staff reads club members');
  check((await other.rpc('admin_set_member_password', { p_member_id: 1, p_password: 'hijacked1' })).error, 'other club cannot reset this member\'s password');

  // Member: sees only their own data, clocks with the sticker code.
  await member.login('ana', 'anapass12');
  check((await member.q('members', 'select')).data?.length === 1, 'member sees only themself');
  await member.q('members', 'update', { values: { full_name: 'Hacker' }, filters: [['id', 'eq', 1]] });
  check((await admin.q('members', 'select', { single: 'single' })).data?.full_name === 'Ana Cruz', 'member cannot edit member details');
  check((await member.rpc('submit_member_clock', { p_race_id: 1, p_barcode: '000000' })).data?.status === 'rejected', 'wrong code rejected');
  check((await member.rpc('submit_member_clock', { p_race_id: 1, p_barcode: '00000126' })).data?.status === 'rejected', 'reference barcode is not the clocking code');
  const clock = await member.rpc('submit_member_clock', { p_race_id: 1, p_barcode: '482715' });
  check(clock.data?.status === 'accepted' && clock.data.flight_seconds > 3 * 3600 - 60, `member clocked with the code (${JSON.stringify(clock.data || clock.error)})`);
  check((await member.rpc('submit_member_nfc_clock', { p_tag: '04A23B1C' })).data?.status === 'duplicate', 'second clocking: duplicate');

  // Show-up and results.
  check((await admin.rpc('admin_show_up_tap', { p_tag: '04A23B1C', p_reader: 'wired' })).data?.status === 'verified', 'show-up verifies the result');
  const day = new Date(new Date(release).getTime() + 8 * 3600e3).toISOString().slice(0, 10);
  const results = await visitor.rpc('public_race_results', { p_club_id: 1, p_race_date: day });
  check(results.data?.length === 1 && results.data[0].rank === 1 && results.data[0].pigeon_ring === 'PH-1 P1' && results.data[0].verification === 'verified', `public results (${JSON.stringify(results.data || results.error)})`);
  check((await visitor.rpc('public_race_summaries', { p_club_id: 1, p_race_date: day })).data?.[0]?.birds_clocked === 1, 'public race summary');
  await admin.q('races', 'update', { values: { lap: 4 }, filters: [['id', 'eq', 1]] });
  check((await visitor.rpc('public_race_summaries', { p_club_id: 1, p_race_date: day })).data?.[0]?.lap === 4, 'race lap shown in the summary');
  const badLap = await admin.q('races', 'update', { values: { lap: 0 }, filters: [['id', 'eq', 1]] });
  check(!!badLap.error, 'lap 0 refused');

  // Moving the race point recalculates the distance (and keeps the history).
  await admin.q('race_points', 'update', { values: { latitude: 13.9 }, filters: [['id', 'eq', 1]] });
  const moved = await admin.q('race_entries', 'select', { columns: 'distance_m', single: 'single' });
  check(Math.abs(moved.data?.distance_m - 22883.959) < 0.01, `race point moved: distance recalculated (${moved.data?.distance_m})`);
  const history = await admin.q('audit_log', 'select', { columns: 'table_name,action,changed_by_name' });
  check(history.data?.some(h => h.table_name === 'clock_events' && h.changed_by_name === 'ana') && history.data.some(h => h.table_name === 'race_entries' && h.action === 'update'), 'change history recorded');
  check((await other.q('audit_log', 'select')).data?.length === 0, 'other club: no change history of club 1');

  // Loft reader: registered by the Super Admin, taps with its key.
  const dev = await sup.rpc('superadmin_register_rfid_device', { p_serial: '5c:cf:7f:12:34:56', p_name: 'Ana loft', p_club_id: 1, p_member_id: 1 });
  const key = dev.data?.device_key;
  check(key && dev.data.serial === '5CCF7F123456', 'reader registered');
  check((await reader.rpc('device_rfid_scan', { p_serial: '5CCF7F123456', p_key: 'wrong', p_tag: '04A23B1C' })).error?.code === '42501', 'reader with a wrong key refused');
  check((await reader.rpc('device_rfid_heartbeat', { p_serial: '5CCF7F123456', p_key: key, p_pending: 1 })).data?.status === 'online', 'reader heartbeat');
  check((await reader.rpc('device_rfid_scan', { p_serial: '5CCF7F123456', p_key: key, p_tag: '04A23B1C' })).data?.status === 'duplicate', 'reader tap of a clocked bird: duplicate');
  check((await admin.rpc('admin_list_rfid_devices')).data?.[0]?.is_online === true, 'club sees its reader online');
  check((await other.rpc('admin_list_rfid_devices')).data?.length === 0, 'other club does not see the reader');

  // Emails: one per account; reset needs a confirmed email.
  check((await member.rpc('account_set_email', { p_email: 'Ana@Example.com' })).data?.email === 'ana@example.com', 'member email saved (lower case)');
  check((await admin.rpc('account_set_email', { p_email: 'ana@example.com' })).error?.message.includes('already used'), 'same email on another account refused');
  check((await visitor.call('functions/avilonx-password-reset', { action: 'reset', username: 'ana' })).error?.code === 'NOT_SET_UP', 'reset email: not set up without a Resend key');

  // Landing page (Supabase-style address, raw answers), club requests, errors.
  const raw = async (name, args = {}, c = visitor) => (await fetch(`${BASE}/api/index.php?r=/rest/v1/rpc/${name}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(args) })).json();
  const today = await raw('public_races_today');
  // "Today" is the Philippine date: the Derby (released 3 hours ago) counts only if that is still today there.
  const phDate = t => new Date(new Date(t).getTime() + 8 * 3600e3).toISOString().slice(0, 10);
  const derbyToday = phDate(release) === phDate(Date.now());
  check(Array.isArray(today) && (derbyToday ? today[0]?.race_name === 'Derby' && today[0].clocked === 1 && today[0].best_speed > 0 : !today.some(r => r.race_name === 'Derby')), `races today on the landing page (${JSON.stringify(today)})`);
  check((await raw('submit_club_request', { p_contact_name: 'Jo', p_club_name: 'New Club', p_phone: '09171234567', p_email: '', p_message: 'Hi' })).ok === true, 'club request sent');
  check((await raw('submit_club_request', { p_contact_name: 'J', p_club_name: 'X', p_phone: '', p_email: '' })).code === '22023', 'club request needs a name and a contact');
  check((await sup.rpc('superadmin_club_requests')).data?.[0]?.club_name === 'New Club', 'Super Admin sees club requests');
  check((await admin.rpc('superadmin_club_requests')).error?.code === '42501', 'club admin cannot see club requests');
  await raw('log_client_error', { p_message: 'Boom', p_page: 'pages/x', p_source: 'x.js:1' });
  await raw('log_client_error', { p_message: 'Boom', p_page: 'pages/x' });
  check((await sup.rpc('superadmin_client_errors')).data?.[0]?.count === 2, 'app errors grouped');

  // Season standings and members' reader status.
  await admin.q('races', 'update', { values: { status: 'closed' }, filters: [['id', 'eq', 1]] });
  const season = await admin.rpc('season_standings', { p_year: null });
  check(season.data?.races === 1 && season.data.lofts[0]?.points === 10 && season.data.lofts[0].wins === 1, `season standings (${JSON.stringify(season.data || season.error)})`);
  check((await member.rpc('member_reader_status')).data?.[0]?.name === 'Ana loft', 'member sees their loft reader');

  // Push notifications: keys, subscribe, queued on a clocking and a race opening.
  const pushKey = (await member.call('functions/avilonx-push', { action: 'keys' })).data?.public;
  check(pushKey && Buffer.from(pushKey, 'base64url').length === 65, 'push keys made');
  check((await member.rpc('push_public_key')).data === pushKey, 'push public key');
  const ecdh = require('node:crypto').createECDH('prime256v1'); ecdh.generateKeys();
  const sub = { p_endpoint: 'https://127.0.0.1:9/push/abc', p_p256dh: ecdh.getPublicKey().toString('base64url'), p_auth: require('node:crypto').randomBytes(16).toString('base64url'), p_device: 'Test' };
  await member.rpc('push_subscribe', sub);
  check((await member.rpc('push_status', { p_endpoint: sub.p_endpoint })).data?.this_device === true, 'this device subscribed');
  await admin.q('races', 'insert', { values: { name: 'Race 2', race_point_id: 1, release_location: 'Batangas', release_latitude: 13.9, release_longitude: 121.05, status: 'open', release_at_utc: new Date().toISOString() } });
  check((await member.rpc('push_test')).data?.ok === true, 'test notification queued');
  await new Promise(r => setTimeout(r, 1500));
  console.log('    push outbox checked by tests/run.sh');

  console.log(failures.length ? `FAILURES:\n${failures.map(f => ' - ' + f).join('\n')}` : 'All API checks passed.');
  process.exit(failures.length ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
