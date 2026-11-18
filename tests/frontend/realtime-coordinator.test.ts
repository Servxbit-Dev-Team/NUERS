import assert from 'node:assert/strict';
import { test } from 'node:test';
import { RealtimeCoordinator, REALTIME_INTERVAL_MS } from '../../src/lib/realtime-coordinator.ts';

const deferred = () => { let resolve!: () => void; const promise = new Promise<void>(r => { resolve = r; }); return { promise, resolve }; };
test('one-second cadence, unchanged revision avoids heavyweight reads', async () => {
  assert.equal(REALTIME_INTERVAL_MS, 1000);
  const c = new RealtimeCoordinator(); let probes=0, reads=0;
  c.probe=async()=>++probes===1; c.subscribe(()=>{reads++});
  for(let i=0;i<10;i++) await c.tick();
  assert.equal(probes,10); assert.equal(reads,1);
});
test('slow multi-widget reads never overlap subsequent batches', async () => {
  const c=new RealtimeCoordinator();const gate=deferred();let calls=0;
  c.subscribe(async()=>{calls++;await gate.promise});c.subscribe(()=>{calls++});
  const first=c.tick();await Promise.resolve();await c.tick();await c.tick();
  assert.equal(calls,2);gate.resolve();await first;await c.tick();assert.equal(calls,4);
});
test('manual requests, offline and hidden state suspend polling', async()=>{
  const c=new RealtimeCoordinator();let calls=0;c.subscribe(()=>{calls++});
  const done=c.beginRequest();await c.tick();assert.equal(calls,0);
  done();done();await c.tick(false);assert.equal(calls,0);await c.tick(true);assert.equal(calls,1);
});
test('Retry-After is respected and the failed revision is retried',async()=>{
  let time=0;const c=new RealtimeCoordinator(()=>time);let calls=0;c.probe=async()=>false;
  c.subscribe(()=>{calls++});c.beginRequest()(429,10);
  time=9999;await c.tick();assert.equal(calls,0);time=10000;await c.tick();assert.equal(calls,1);
  await c.tick();assert.equal(calls,1);
});
test('unsubscribe stops old-page refresh; re-enabled editor catches up',async()=>{
  const c=new RealtimeCoordinator();let old=0,next=0;
  const stop=c.subscribe(()=>{old++});stop();c.subscribe(()=>{next++});c.probe=async()=>false;
  await c.tick();assert.equal(next,0);c.invalidate();await c.tick();assert.equal(old,0);assert.equal(next,1);
});
test('one listener failure does not suppress another widget',async()=>{
  const c=new RealtimeCoordinator();let healthy=0;c.subscribe(()=>{throw Error('failure')});c.subscribe(()=>{healthy++});
  await c.tick();await c.tick();assert.equal(healthy,2);
});
test('failed heartbeat never refreshes data; resumed heartbeat works',async()=>{
  const c=new RealtimeCoordinator();let reads=0;c.subscribe(()=>{reads++});c.probe=async()=>{throw Error('offline')};
  await c.tick();assert.equal(reads,0);c.probe=async()=>true;await c.tick();assert.equal(reads,1);
});
