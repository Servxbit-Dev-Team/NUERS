import {createContext,useContext} from 'react';
export type MerchantWorkAccess={owner:boolean;role:string;permissions:string[];scope:string;terminal_scope:string};
export const MerchantWorkContext=createContext<MerchantWorkAccess|null>(null);
export const useMerchantWorkAccess=()=>useContext(MerchantWorkContext);
export function workPermission(a:MerchantWorkAccess|null,p:string){return !a||a.owner||a.permissions.includes(p)}
