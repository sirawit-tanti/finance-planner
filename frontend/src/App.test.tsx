import { render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import App from "./App";

describe("App", () => {
  afterEach(() => vi.unstubAllGlobals());

  it("shows a success message when the API health check passes", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue({ ok: true }));
    render(<App />);
    expect(await screen.findByText("เชื่อมต่อ API สำเร็จ")).toBeInTheDocument();
  });

  it("shows an error message when the API is unreachable", async () => {
    vi.stubGlobal("fetch", vi.fn().mockRejectedValue(new Error("network")));
    render(<App />);
    expect(await screen.findByText("เชื่อมต่อ API ไม่ได้")).toBeInTheDocument();
  });
});
