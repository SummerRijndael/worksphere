/**
 * Standalone Call App Entry Point
 * Minimal Vue app — only loads Echo + WebRTC dependencies.
 * No router, no sidebar, no full Pinia.
 */
import '../css/call.css';
import { Buffer } from 'buffer';
import process from 'process';
import EventEmitter from 'events';
import util from 'util';

// Polyfills for simple-peer
window.Buffer = Buffer;
window.process = process;
window.global = window;
(window as any).EventEmitter = EventEmitter as any;
(window as any).util = util;

import { createApp } from 'vue';
import { createPinia } from 'pinia';
import CallApp from './views/call/CallApp.vue';

const app = createApp(CallApp);
const pinia = createPinia();
app.use(pinia);

app.config.errorHandler = (err: unknown, _instance, info: string) => {
  console.error('[Call Error]', err);
  console.error('[Info]', info);
};

app.mount('#call-app');
