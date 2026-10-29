import {apiFetch, readJsonResponse} from './api-url';
import {checkRegistrationResponse} from './registration-verification';

export const VERIFICATION_MAX_BYTES = 100 * 1024 * 1024;
export function verificationFileError(file: File) {
  return !['application/pdf','image/png','image/jpeg'].includes(file.type) || file.size < 1 || file.size > VERIFICATION_MAX_BYTES
    ? 'Choose a PDF, PNG or JPG no larger than 100 MB.' : null;
}

type Options = {registration?: {challenge_id: string; verification_token: string}; onProgress?: (percent: number) => void};
export async function uploadVerificationDocument<T>(kind: string, file: File, options: Options = {}): Promise<T> {
  const error = verificationFileError(file); if (error) throw new Error(error);
  const base = options.registration ? '/api/registration/document-uploads' : '/api/merchant/verification-uploads';
  const proof: Record<string,string> = options.registration || {};
  async function request<R>(path: string, body: FormData | string, retry = false): Promise<R> {
    for (let attempt = 0; ; attempt++) {
      let response: Response;
      try { response = await apiFetch(path, {method:'POST', body}); }
      catch (e) { if (!retry || attempt >= 2) throw e; await new Promise(resolve=>setTimeout(resolve,800*(attempt+1))); continue; }
      if (retry && response.status >= 500 && attempt < 2) { await new Promise(resolve=>setTimeout(resolve,800*(attempt+1))); continue; }
      if (retry && response.status === 429 && attempt < 2) {
        const wait = Number(response.headers.get('Retry-After'));
        if (Number.isFinite(wait) && wait > 0 && wait <= 60) { await new Promise(resolve=>setTimeout(resolve,(wait+1)*1000)); continue; }
      }
      return readJsonResponse<R>(await checkRegistrationResponse(response));
    }
  }
  options.onProgress?.(0);
  const upload = await request<{id:string;chunk_bytes:number}>(base, JSON.stringify({...proof,kind,filename:file.name,bytes:file.size}));
  if (upload.chunk_bytes !== 1048576) throw new Error('Unsupported upload part size. Refresh and try again.');
  for (let offset=0,index=0;offset<file.size;offset+=upload.chunk_bytes,index++) {
    const body = new FormData();
    Object.entries(proof).forEach(([key,value])=>body.set(key,value));
    body.set('index',String(index)); body.set('file',file.slice(offset,offset+upload.chunk_bytes),'part.bin');
    const received = await request<{next_index:number}>(`${base}/${upload.id}/chunks`,body,true);
    if (received.next_index !== index+1) throw new Error('Upload progress did not match. Select the file again.');
    options.onProgress?.(Math.min(99,Math.floor(Math.min(file.size,offset+upload.chunk_bytes)*100/file.size)));
  }
  const result = await request<T>(`${base}/${upload.id}/complete`,JSON.stringify(proof),true);
  options.onProgress?.(100); return result;
}
