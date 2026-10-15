import { createContext, useContext, useEffect, useState, useRef, type ReactNode } from "react";
import {storedToken,TOKEN_KEY,SESSION_INVALID_EVENT} from "./session-store";
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
  sessionError: string | null;
  sessionInvalid: boolean;
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
  const [sessionError,setSessionError]=useState<string|null>(null);
  const [sessionInvalid,setSessionInvalid]=useState(false);
  const activeSession=useRef<Session|null>(null),profileGeneration=useRef(0),profileLoaded=useRef(false);

  useEffect(() => {
    let mounted=true,checking=false;
    function apply(next:Session|null){
      if(!mounted)return;
      const previous=activeSession.current;
      activeSession.current=next;
      setSession(next);setUser(next?.user??null);
      if(!next){profileLoaded.current=false;profileGeneration.current++;setProfile(null);setLoading(false)}
      else {setSessionInvalid(false);setSessionError(null);if(previous?.user.id!==next.user.id||previous.access_token!==next.access_token||!profileLoaded.current)fetchProfile(next.user.id);else setLoading(false)}
    }
    async function verify(){
      if(checking||!mounted)return;
      checking=true;
      try{
        const result=await supabase.auth.getSession();
        if(!mounted||result.stale)return;
        if(result.error){setSessionError('Unable to reach NUERS. Retrying your session check…');return;}
        setSessionError(null);apply(result.data.session);
      } finally {checking=false}
    }
    const invalid=()=>{setSessionInvalid(true);setSessionError(null);apply(null)};
    const storage=(e:StorageEvent)=>{if(e.key===TOKEN_KEY||e.key===null){if(!storedToken())apply(null);else {profileGeneration.current++;setProfile(null);setLoading(true);verify()}}};
    const resume=()=>{if(!document.hidden)verify()};
    window.addEventListener(SESSION_INVALID_EVENT,invalid);
    window.addEventListener('storage',storage);
    window.addEventListener('focus',resume);
    window.addEventListener('online',resume);
    document.addEventListener('visibilitychange',resume);
    const {data:{subscription}}=supabase.auth.onAuthStateChange((_event,next)=>apply(next));
    verify();
    const interval=window.setInterval(verify,15000);
    return()=>{mounted=false;profileGeneration.current++;window.clearInterval(interval);subscription.unsubscribe();window.removeEventListener(SESSION_INVALID_EVENT,invalid);window.removeEventListener('storage',storage);window.removeEventListener('focus',resume);window.removeEventListener('online',resume);document.removeEventListener('visibilitychange',resume)};
  }, []);

  async function fetchProfile(userId: string) {
    const generation=++profileGeneration.current;
    try {
      const { data,error } = await supabase
        .from<Profile>("profiles")
        .select("role, full_name, organization, tin, tin_bound_at, profile_photo_url")
        .eq("id", userId)
        .maybeSingle();
      if(generation===profileGeneration.current&&activeSession.current?.user.id===userId){profileLoaded.current=!!data&&!error;setProfile(data);if(error)setSessionError('Unable to verify account permissions. Retrying…');}
    } catch {
      if(generation===profileGeneration.current)setProfile(null);
    } finally {
      if(generation===profileGeneration.current)setLoading(false);
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
    <AuthContext.Provider value={{ user, session, profile, loading, sessionError, sessionInvalid, signIn, completeGoogleSignIn, signUp, signOut }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used within AuthProvider");
  return ctx;
}
