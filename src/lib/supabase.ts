import { apiFetch } from './api-url';
import {storedToken,browserDevice,invalidateToken,TOKEN_KEY} from "./session-store";
export type LaravelUser = {
  id: string;
  email: string;
  user_metadata?: Record<string, unknown>;
};

export type LaravelSession = {
  access_token: string;
  token_type: string;
  mfa_required?: boolean;
  expires_at?: string | null;
  absolute_expires_at?: string | null;
  user: LaravelUser;
};

type ApiError = { message: string };
type ApiResult<T> = { data: T | null; error: ApiError | null; count?: number | null };
type AuthListener = (event: string, session: LaravelSession | null) => void;

const listeners = new Set<AuthListener>();

function storeSession(session: LaravelSession | null) {
  try {
    if (typeof window === "undefined" || !window.localStorage) return;
    if (session?.access_token) window.localStorage.setItem(TOKEN_KEY, session.access_token);
    else window.localStorage.removeItem(TOKEN_KEY);
  } catch {
    // Private or restricted browser contexts can block storage.
  }
}

function notify(event: string, session: LaravelSession | null) {
  listeners.forEach((listener) => listener(event, session));
}

async function request<T>(path: string, init: RequestInit = {}): Promise<ApiResult<T>> {
  const headers = new Headers(init.headers);
  headers.set("Content-Type", "application/json");
  headers.set("Accept", "application/json");

  const token = storedToken();
  const device=browserDevice();if(device)headers.set("X-NUERS-Device",device);
  if (token) headers.set("Authorization", `Bearer ${token}`);

  try {
    const response = await apiFetch(path, { ...init, headers });
    const contentType = response.headers.get("Content-Type") ?? "";

    if (!contentType.toLowerCase().includes("application/json")) {
      return {
        data: null,
        error: { message: "API endpoint did not return JSON. Check the live API deployment path." },
      };
    }

    const payload = await response.json().catch(() => ({}));

    if(response.status===401&&!path.startsWith("/api/auth/login")&&!path.startsWith("/auth/google"))invalidateToken(token);
    if (!response.ok) {
      const message =
        payload?.message ||
        Object.values(payload?.errors ?? {})?.flat()?.[0] ||
        "Request failed.";

      return { data: null, error: { message: String(message) } };
    }

    const responseData = Object.prototype.hasOwnProperty.call(payload, "data")
      ? payload.data
      : payload;

    return {
      data: responseData as T,
      error: null,
      count: typeof payload?.count === "number" ? payload.count : null,
    };
  } catch (error) {
    return {
      data: null,
      error: { message: error instanceof Error ? error.message : "Network request failed." },
    };
  }
}

class LaravelQuery<T = any> implements PromiseLike<ApiResult<T>> {
  private table: string;
  private action: "select" | "insert" | "update" | "delete" = "select";
  private values: unknown;
  private eqFilters: Record<string, unknown> = {};
  private inFilters: Record<string, unknown[]> = {};
  private orFilter = "";
  private orderColumn = "";
  private ascending = false;
  private limitValue?: number;
  private offsetValue?: number;
  private searchValue = "";
  private countMode?: string;
  private headOnly = false;
  private singleRow = false;
  private maybeSingleRow = false;

  constructor(table: string) {
    this.table = table;
  }

  select(_columns = "*", options?: { count?: string; head?: boolean }) {
    this.countMode = options?.count;
    this.headOnly = Boolean(options?.head);
    return this;
  }

  insert(values: unknown) {
    this.action = "insert";
    this.values = values;
    return this;
  }

  update(values: unknown) {
    this.action = "update";
    this.values = values;
    return this;
  }

  delete() {
    this.action = "delete";
    return this;
  }

  eq(column: string, value: unknown) {
    this.eqFilters[column] = value;
    return this;
  }

  in(column: string, values: unknown[]) {
    this.inFilters[column] = values;
    return this;
  }

  or(filter: string) {
    this.orFilter = filter;
    return this;
  }

  order(column: string, options?: { ascending?: boolean }) {
    this.orderColumn = column;
    this.ascending = Boolean(options?.ascending);
    return this;
  }

  limit(value: number) {
    this.limitValue = value;
    return this;
  }

  range(from: number, to: number) {
    this.offsetValue = from; this.limitValue = to - from + 1; return this;
  }

  search(value: string) { this.searchValue = value; return this; }

  maybeSingle() {
    this.maybeSingleRow = true;
    return this.execute();
  }

  single() {
    this.singleRow = true;
    return this.execute();
  }

  then<TResult1 = ApiResult<T>, TResult2 = never>(
    onfulfilled?: ((value: ApiResult<T>) => TResult1 | PromiseLike<TResult1>) | null,
    onrejected?: ((reason: unknown) => TResult2 | PromiseLike<TResult2>) | null,
  ): PromiseLike<TResult1 | TResult2> {
    return this.execute().then(onfulfilled, onrejected);
  }

  private execute(): Promise<ApiResult<T>> {
    const params = new URLSearchParams();
    if (this.countMode) params.set("count", this.countMode);
    if (this.headOnly) params.set("head", "1");
    if (this.orderColumn) {
      params.set("order", this.orderColumn);
      params.set("ascending", this.ascending ? "1" : "0");
    }
    if (this.offsetValue !== undefined) params.set("offset", String(this.offsetValue));
    if (this.searchValue) params.set("search", this.searchValue);
    if (this.limitValue) params.set("limit", String(this.limitValue));
    if (this.singleRow) params.set("single", "1");
    if (this.maybeSingleRow) params.set("maybeSingle", "1");
    if (Object.keys(this.eqFilters).length) params.set("eq", JSON.stringify(this.eqFilters));
    if (Object.keys(this.inFilters).length) params.set("in", JSON.stringify(this.inFilters));
    if (this.orFilter) params.set("or", this.orFilter);

    const query = params.toString();
    const path = `/api/tables/${this.table}${query ? `?${query}` : ""}`;

    if (this.action === "select") return request<T>(path);
    if (this.action === "insert") {
      return request<T>(path, { method: "POST", body: JSON.stringify({ values: this.values }) });
    }
    if (this.action === "update") {
      return request<T>(path, { method: "PUT", body: JSON.stringify({ values: this.values }) });
    }
    return request<T>(path, { method: "DELETE" });
  }
}

export const supabase = {
  auth: {
    async completeGoogleSignIn() {
      const { data, error } = await request<{ session: LaravelSession; user: LaravelUser; role: string }>(
        "/auth/google/session", { credentials: "same-origin" },
      );
      const session = data?.session ?? null;
      if (session) {
        storeSession(session);
        notify("SIGNED_IN", session);
      }
      return { data: { session, user: data?.user ?? null, role: data?.role ?? null }, error };
    },

    async getSession(options: { active?: boolean } = {}) {
      const token=storedToken();
      if(!token)return {data:{session:null},error:null,stale:false};
      const {data,error}=await request<{session:LaravelSession|null}>("/api/auth/session", { headers: options.active ? { "X-NUERS-Activity": "1" } : undefined });
      if(storedToken()!==token)return {data:{session:null},error:null,stale:true};
      if(error)return {data:{session:null},error,stale:false};
      const session=data?.session??null;
      if(!session)invalidateToken(token);
      else storeSession(session);
      return {data:{session},error:null,stale:false};
    },

    onAuthStateChange(callback: AuthListener) {
      listeners.add(callback);
      return {
        data: {
          subscription: {
            unsubscribe: () => {
              listeners.delete(callback);
            },
          },
        },
      };
    },

    async signInWithPassword(credentials: { email: string; password: string }) {
      const { data, error } = await request<{ session: LaravelSession; user: LaravelUser }>(
        "/api/auth/login",
        { method: "POST", body: JSON.stringify(credentials) },
      );

      const session = data?.session ?? null;
      if (session) {storeSession(session);notify("SIGNED_IN", session);}

      return { data: { session, user: data?.user ?? null }, error };
    },

    async signUp(credentials: { email: string; password: string; meta?: Record<string, string> }) {
      const { data, error } = await request<{ session: LaravelSession; user: LaravelUser }>(
        "/api/auth/signup",
        { method: "POST", body: JSON.stringify(credentials) },
      );

      const session = data?.session ?? null;
      if (session) {storeSession(session);notify("SIGNED_IN", session);}

      return { data: { session, user: data?.user ?? null }, error };
    },

    async signOut() {
      const token=storedToken();
      await request("/api/auth/logout", { method: "POST", body: "{}" });
      if(!storedToken()||storedToken()===token){storeSession(null);notify("SIGNED_OUT", null);}
    },
  },

  from<T = any>(table: string) {
    return new LaravelQuery<T>(table);
  },
};

export async function createMerchantAccount(payload: {
  email: string;
  password: string;
  merchantId: string;
  businessName: string;
}) {
  return request<{ success: boolean; userId: string }>("/api/merchant-accounts", {
    method: "POST",
    body: JSON.stringify(payload),
  });
}
