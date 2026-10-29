export const TOKEN_KEY='nuers_api_token';
export const SESSION_INVALID_EVENT='nuers:session-invalid';
const DEVICE_KEY='nuers_browser_device';
let memoryDevice:string|undefined;
export function storedToken(){try{return typeof window==='undefined'?null:window.localStorage.getItem(TOKEN_KEY)}catch{return null}}
export function browserDevice(){if(typeof window==='undefined')return null;try{let device=window.localStorage.getItem(DEVICE_KEY);if(!device){device=crypto.randomUUID();window.localStorage.setItem(DEVICE_KEY,device)}return device}catch{return memoryDevice??=crypto.randomUUID()}}
export function invalidateToken(expected:string|null){if(!expected||storedToken()!==expected)return false;try{window.localStorage.removeItem(TOKEN_KEY)}catch{}window.dispatchEvent(new Event(SESSION_INVALID_EVENT));return true}
