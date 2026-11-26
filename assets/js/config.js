// AvilonX PHP + MySQL: the website's own server (api/index.php).
// Code written for Supabase builds `${SUPABASE_URL}/rest/v1/rpc/<name>`: with
// this address that becomes api/index.php?r=/rest/v1/rpc/<name>, which the PHP
// API answers the same way Supabase does. The key is not used (the PHP session
// cookie identifies the signed-in account).
export const SUPABASE_URL = new URL('../../api/index.php?r=', import.meta.url).href;
export const SUPABASE_KEY = 'php';
