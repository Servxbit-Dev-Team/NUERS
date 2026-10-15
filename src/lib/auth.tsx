import { createContext, useContext, useEffect, useState, type ReactNode } from "react";
import { supabase } from "./supabase";
import type { LaravelSession as Session, LaravelUser as User } from "./supabase";

interface AuthContextType {
  user: User | null;
  session: Session | null;
  profile: {
    role: string;
    full_name: string;
    organization: string;
    tin?: string | null;
    tin_bound_at?: string | null;
    profile_photo_url?: string | null;
  } | null;
  loading: boolean;
  signIn: (email: string, password: string) => Promise<{ error: string | null; role: string | null }>;
  completeGoogleSignIn: () => Promise<{ error: string | null; role: string | null }>;
  signUp: (email: string, password: string, meta?: Record<string, string>) => Promise<{ error: string | null }>;
  signOut: () => Promise<void>;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);
type Profile = NonNullable<AuthContextType["profile"]>;

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [session, setSession] = useState<Session | null>(null);
  const [profile, setProfile] = useState<AuthContextType["profile"]>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    supabase.auth.getSession()
      .then(({ data: { session } }) => {
        setSession(session);
        setUser(session?.user ?? null);
        if (session?.user) {
          fetchProfile(session.user.id);
        } else {
          setLoading(false);
        }
      })
      .catch(() => {
        setSession(null);
        setUser(null);
        setProfile(null);
        setLoading(false);
      });

    const { data: { subscription } } = supabase.auth.onAuthStateChange((_event, session) => {
      setSession(session);
      setUser(session?.user ?? null);
      if (session?.user) {
        fetchProfile(session.user.id);
      } else {
        setProfile(null);
        setLoading(false);
      }
    });

    return () => subscription.unsubscribe();
  }, []);

  async function fetchProfile(userId: string) {
    try {
      const { data } = await supabase
        .from<Profile>("profiles")
        .select("role, full_name, organization, tin, tin_bound_at, profile_photo_url")
        .eq("id", userId)
        .maybeSingle();
      setProfile(data);
    } catch {
      setProfile(null);
    } finally {
      setLoading(false);
    }
  }

  async function signIn(email: string, password: string) {
    const { data, error } = await supabase.auth.signInWithPassword({ email, password });
    if (error) return { error: error.message, role: null };
    if (!data.user) return { error: "Unable to load authenticated user.", role: null };
    const { data: prof } = await supabase
      .from<Pick<Profile, "role">>("profiles")
      .select("role")
      .eq("id", data.user.id)
      .maybeSingle();
    return { error: null, role: prof?.role ?? null };
  }

  async function signUp(email: string, password: string, meta?: Record<string, string>) {
    const { error } = await supabase.auth.signUp({ email, password, meta });
    if (error) return { error: error.message };

    return { error: null };
  }

  async function completeGoogleSignIn() {
    const { data, error } = await supabase.auth.completeGoogleSignIn();
    if (error) return { error: error.message, role: null };
    if (!data.user || !data.role) return { error: "Unable to load your NUERS account.", role: null };
    return { error: null, role: data.role };
  }

  async function signOut() {
    await supabase.auth.signOut();
  }

  return (
    <AuthContext.Provider value={{ user, session, profile, loading, signIn, completeGoogleSignIn, signUp, signOut }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used within AuthProvider");
  return ctx;
}
