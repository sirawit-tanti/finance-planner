import { describe, expect, it } from "vitest";
import { parseEnv } from "./env";

describe("parseEnv", () => {
  it("removes trailing slashes from the API url", () => {
    expect(
      parseEnv({ VITE_API_URL: "https://api.example.com//" }).VITE_API_URL,
    ).toBe("https://api.example.com");
  });

  it("rejects a missing or invalid API url", () => {
    expect(() => parseEnv({})).toThrow();
    expect(() => parseEnv({ VITE_API_URL: "not-a-url" })).toThrow();
  });
});
