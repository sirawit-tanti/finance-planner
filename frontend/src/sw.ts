/// <reference lib="webworker" />
import {
  cleanupOutdatedCaches,
  createHandlerBoundToURL,
  precacheAndRoute,
} from "workbox-precaching";
import { NavigationRoute, registerRoute } from "workbox-routing";

declare const self: ServiceWorkerGlobalScope & {
  __WB_MANIFEST: Array<string | { url: string; revision: string | null }>;
};

// Background Sync is not in TypeScript's webworker lib, so declare what we use.
interface SyncEvent extends ExtendableEvent {
  readonly tag: string;
}

// 1) Precache the app shell (injected at build time) so the app opens offline.
precacheAndRoute(self.__WB_MANIFEST);
cleanupOutdatedCaches();

// 2) Any in-app route (SPA) falls back to index.html when offline.
//    /api/* is excluded: API calls are never served from cache.
registerRoute(
  new NavigationRoute(createHandlerBoundToURL("index.html"), {
    denylist: [/^\/api\//],
  }),
);

// 3) The app tells us when the user accepts an update.
self.addEventListener("message", (event) => {
  if (event.data?.type === "SKIP_WAITING") {
    void self.skipWaiting();
  }
});

// 4) Background Sync (Chromium only; iOS Safari does not support it).
//    The sync logic needs IndexedDB data and the auth token, which live in the
//    app, so the worker only wakes any open app window and asks it to sync.
//    If no window is open, sync runs the next time the app is opened.
self.addEventListener("sync", ((event: SyncEvent) => {
  if (event.tag !== "sync-outbox") return;
  event.waitUntil(
    self.clients.matchAll({ type: "window" }).then((clients) => {
      clients.forEach((client) => client.postMessage({ type: "RUN_SYNC" }));
    }),
  );
}) as EventListener);
