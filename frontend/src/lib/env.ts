import { z } from "zod";

const envSchema = z.object({
  // Strip trailing slashes so `${VITE_API_URL}/api/...` never has a double slash.
  VITE_API_URL: z
    .string()
    .url()
    .transform((value) => value.replace(/\/+$/, "")),
});

export type Env = z.infer<typeof envSchema>;

// Pure function so it can be unit-tested without Vite's runtime.
export function parseEnv(raw: Record<string, unknown>): Env {
  return envSchema.parse(raw);
}

// Fails fast at startup with a clear error if the variable is missing/invalid.
export const env = parseEnv(import.meta.env);
