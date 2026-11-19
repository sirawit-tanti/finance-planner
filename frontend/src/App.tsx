import { useEffect, useState } from "react";
import { env } from "./lib/env";

type ApiStatus = "checking" | "ok" | "error";

export default function App() {
  const [apiStatus, setApiStatus] = useState<ApiStatus>("checking");
  const [online, setOnline] = useState(navigator.onLine);

  // Track browser connectivity (the Online/Offline indicator, Phase 5 extends it).
  useEffect(() => {
    const goOnline = () => setOnline(true);
    const goOffline = () => setOnline(false);
    window.addEventListener("online", goOnline);
    window.addEventListener("offline", goOffline);
    return () => {
      window.removeEventListener("online", goOnline);
      window.removeEventListener("offline", goOffline);
    };
  }, []);

  // Setup check: can the PWA reach the Laravel API?
  useEffect(() => {
    const controller = new AbortController();
    setApiStatus("checking");
    fetch(`${env.VITE_API_URL}/api/health`, { signal: controller.signal })
      .then((res) => setApiStatus(res.ok ? "ok" : "error"))
      .catch(() => {
        if (!controller.signal.aborted) setApiStatus("error");
      });
    return () => controller.abort();
  }, [online]);

  const apiText: Record<ApiStatus, string> = {
    checking: "กำลังตรวจสอบการเชื่อมต่อ API...",
    ok: "เชื่อมต่อ API สำเร็จ",
    error: "เชื่อมต่อ API ไม่ได้",
  };

  return (
    <main className="min-h-screen bg-white text-slate-900 dark:bg-slate-950 dark:text-slate-100 flex items-center justify-center p-6">
      <div className="w-full max-w-sm rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-3">
        <h1 className="text-xl font-semibold">Finance Planner</h1>
        <p className="text-sm text-slate-500 dark:text-slate-400">
          Phase 3: ตรวจสอบการติดตั้ง
        </p>
        <p className="text-sm">
          สถานะเครือข่าย: {online ? "Online" : "Offline"}
        </p>
        <p className="text-sm">{apiText[apiStatus]}</p>
      </div>
    </main>
  );
}
