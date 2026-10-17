import {test} from 'node:test';import assert from 'node:assert/strict';
import {ApiRateLimitError,rateLimitError,retrySeconds} from '../../src/lib/rate-limit.ts';
test('generic throttling becomes an actionable message with exact Retry-After seconds',()=>{
 const e=rateLimitError(new Response('',{status:429,headers:{'Retry-After':'42'}}),{message:'Too Many Attempts.'});
 assert.ok(e instanceof ApiRateLimitError);assert.equal(e.status,429);assert.equal(e.retryAfterSeconds,42);assert.match(e.message,/42 seconds/);
});
test('quota body and HTTP-date retry windows remain meaningful; invalid retry values do not lock the UI',()=>{
 assert.equal(rateLimitError(new Response('',{status:429}),{retry_after:15}).retryAfterSeconds,15);
 assert.equal(rateLimitError(new Response('',{status:429,headers:{'Retry-After':'Thu, 01 Jan 1970 00:01:00 GMT'}}),{},30000).retryAfterSeconds,30);
 const original=rateLimitError(new Response('',{status:429}),{message:'Another export is running.'});assert.equal(original.retryAfterSeconds,0);assert.match(original.message,/Another export/);
 assert.equal(rateLimitError(new Response('',{status:429,headers:{'Retry-After':'invalid'}}),{}).retryAfterSeconds,0);
});
test('MFA retry countdown uses elapsed wall time and unlocks after suspension',()=>{
 assert.equal(retrySeconds(10000,9001),1);assert.equal(retrySeconds(10000,10000),0);assert.equal(retrySeconds(10000,60000),0);
});
