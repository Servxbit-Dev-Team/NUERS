// Legacy navigation uses the maintained production workspace.
import { useAuth } from "@/lib/auth";
import { AdminUsers } from "@/pages/admin/users";
export function UserManagement() {
  const { profile } = useAuth();
  return ["admin", "super_admin"].includes(profile?.role ?? "") ? (
    <AdminUsers />
  ) : (
    <p role="alert">User administration requires an administrator account.</p>
  );
}
