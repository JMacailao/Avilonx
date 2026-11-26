// AvilonX (PHP + MySQL): the website's connection to its own PHP API (api/).
//
// The pages were written for the Supabase client, so this file offers the same
// small set of calls (supabase.from(...).select().eq()..., supabase.rpc(),
// supabase.auth, supabase.storage, supabase.functions) and sends them to
// api/index.php. Every rule about who may read or change what is checked by
// the PHP API, never here.
const API = new URL('../../api/index.php', import.meta.url).href;

async function call(route, body, { form = false } = {}) {
  let response;
  try {
    response = await fetch(`${API}?r=${encodeURIComponent(route)}`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: form ? { Accept: 'application/json' } : { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: form ? body : JSON.stringify(body ?? {}),
    });
  } catch {
    return { data: null, error: { message: 'Cannot reach the AvilonX server. Check the connection and try again.', code: 'NETWORK' }, count: null };
  }
  let payload = null;
  try { payload = await response.json(); } catch { /* not JSON */ }
  if (!response.ok || payload?.error) {
    const error = payload?.error || { message: `Server error (${response.status}).` };
    return { data: null, error: { status: response.status, ...error }, count: null };
  }
  return { data: payload?.data ?? null, error: null, count: payload?.count ?? null };
}

// Table queries: supabase.from('members').select('id,name').eq('club_id', 1).order('name')
class Query {
  constructor(table) {
    this.q = { table, op: 'select', columns: '*', filters: [], order: [], limit: null, single: null, values: null, returning: null, count: null, head: false, onConflict: null };
  }
  select(columns = '*', options = {}) {
    if (this.q.op === 'select') this.q.columns = columns; else this.q.returning = columns;
    if (options.count) this.q.count = options.count;
    if (options.head) this.q.head = true;
    return this;
  }
  insert(values) { this.q.op = 'insert'; this.q.values = values; return this; }
  upsert(values, options = {}) { this.q.op = 'upsert'; this.q.values = values; this.q.onConflict = options.onConflict || null; return this; }
  update(values) { this.q.op = 'update'; this.q.values = values; return this; }
  delete() { this.q.op = 'delete'; return this; }
  filter(column, operator, value) { this.q.filters.push([column, operator, value]); return this; }
  eq(column, value) { return this.filter(column, 'eq', value); }
  neq(column, value) { return this.filter(column, 'neq', value); }
  gt(column, value) { return this.filter(column, 'gt', value); }
  gte(column, value) { return this.filter(column, 'gte', value); }
  lt(column, value) { return this.filter(column, 'lt', value); }
  lte(column, value) { return this.filter(column, 'lte', value); }
  is(column, value) { return this.filter(column, 'is', value); }
  in(column, values) { return this.filter(column, 'in', Array.from(values ?? [])); }
  ilike(column, value) { return this.filter(column, 'ilike', value); }
  order(column, options = {}) { this.q.order.push([column, options.ascending !== false]); return this; }
  limit(count) { this.q.limit = count; return this; }
  single() { this.q.single = 'single'; return this; }
  maybeSingle() { this.q.single = 'maybe'; return this; }
  then(resolve, reject) { return call('query', this.q).then(resolve, reject); }
}

// Signed-in account: kept by the PHP session (a cookie), so pages that ask
// for the session get it from the server.
let sessionCache;
const listeners = new Set();
const auth = {
  async getSession() {
    if (sessionCache !== undefined) return { data: { session: sessionCache }, error: null };
    const { data, error } = await call('auth/session');
    sessionCache = error ? null : data?.session ?? null;
    return { data: { session: sessionCache }, error: null };
  },
  async getUser() {
    const { data: { session } } = await auth.getSession();
    return { data: { user: session?.user ?? null }, error: null };
  },
  async signInWithPassword({ email, password }) {
    const username = String(email || '').split('@')[0];
    const { data, error } = await call('auth/login', { username, password });
    if (error) return { data: { user: null, session: null }, error };
    sessionCache = data.session;
    listeners.forEach(fn => { try { fn('SIGNED_IN', sessionCache); } catch { /* listener error */ } });
    return { data: { user: data.session.user, session: data.session }, error: null };
  },
  async signOut() {
    const { error } = await call('auth/logout');
    sessionCache = null;
    listeners.forEach(fn => { try { fn('SIGNED_OUT', null); } catch { /* listener error */ } });
    return { error };
  },
  async updateUser({ password }) {
    const { data, error } = await call('auth/password', { password });
    return { data: { user: data?.user ?? null }, error };
  },
  onAuthStateChange(fn) {
    listeners.add(fn);
    return { data: { subscription: { unsubscribe: () => listeners.delete(fn) } } };
  },
};

// Files (bird photos, clocking videos, club logos) are kept by the PHP API.
const fileUrl = (bucket, path, token = '') => `${API}?r=${encodeURIComponent('storage/file')}&bucket=${encodeURIComponent(bucket)}&path=${encodeURIComponent(path)}${token ? `&token=${encodeURIComponent(token)}` : ''}`;
const storage = {
  from(bucket) {
    return {
      async upload(path, file, options = {}) {
        const form = new FormData();
        form.append('bucket', bucket); form.append('path', path);
        form.append('content_type', options.contentType || file?.type || 'application/octet-stream');
        form.append('upsert', options.upsert ? '1' : '0');
        form.append('file', file, path.split('/').pop());
        return call('storage/upload', form, { form: true });
      },
      async remove(paths) { return call('storage/remove', { bucket, paths }); },
      async createSignedUrl(path, expiresIn = 3600) {
        const { data, error } = await call('storage/sign', { bucket, paths: [path], expires_in: expiresIn });
        if (error) return { data: null, error };
        return { data: { signedUrl: fileUrl(bucket, path, data[0]?.token) }, error: null };
      },
      async createSignedUrls(paths, expiresIn = 3600) {
        const { data, error } = await call('storage/sign', { bucket, paths, expires_in: expiresIn });
        if (error) return { data: null, error };
        return { data: data.map(item => ({ path: item.path, signedUrl: item.token ? fileUrl(bucket, item.path, item.token) : null, error: item.token ? null : 'Not found' })), error: null };
      },
      getPublicUrl(path) { return { data: { publicUrl: fileUrl(bucket, path) } }; },
    };
  },
};

// Server functions (e.g. the password reset email).
const functions = {
  async invoke(name, options = {}) {
    const result = await call(`functions/${name}`, options.body ?? {});
    if (result.error) {
      const detail = result.error;
      result.error = { message: detail.message, context: { json: async () => ({ error: detail.message }) } };
    }
    return { data: result.data, error: result.error };
  },
};

// Live updates are not available with PHP: the pages already refresh by
// themselves every few seconds, so a channel does nothing.
const channel = () => ({ on() { return this; }, subscribe() { return this; }, unsubscribe() {} });

export const supabase = {
  from: table => new Query(table),
  rpc: (name, args = {}) => call(`rpc/${name}`, args),
  auth,
  storage,
  functions,
  channel,
  removeChannel: () => {},
};
