/** API-owned permission catalog; never invent grants in the browser. */
export type MerchantPermission = { id: string; label: string; description: string; scope: "assigned" | "business"; requires: string[] };
export type MerchantPermissionGroup = { id: string; label: string; permissions: MerchantPermission[] };
export function toggleRolePermission(current: string[], id: string, checked: boolean, groups: MerchantPermissionGroup[]): string[] {
  const catalog = new Map(groups.flatMap(group => group.permissions).map(permission => [permission.id, permission]));
  if (!catalog.has(id)) return current;
  const selected = new Set(current.filter(permission => catalog.has(permission)));
  if (checked) {
    const add = (permission: string) => {
      if (selected.has(permission) || !catalog.has(permission)) return;
      selected.add(permission);
      catalog.get(permission)!.requires.forEach(add);
    };
    add(id);
  } else {
    selected.delete(id);
    let changed = true;
    while (changed) {
      changed = false;
      for (const permission of selected) if (catalog.get(permission)!.requires.some(required => !selected.has(required))) {
        selected.delete(permission); changed = true;
      }
    }
  }
  return [...catalog.keys()].filter(permission => selected.has(permission));
}
