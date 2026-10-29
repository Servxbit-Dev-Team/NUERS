import {useId} from 'react';
import type {ReviewPreset} from './review-presets';
import './review-text-field.css';
export function ReviewTextField({label,value,onChange,presets,multiline=false,disabled=false,max=2000,placeholder,help}:{label:string;value:string;onChange:(value:string)=>void;presets:ReviewPreset[];multiline?:boolean;disabled?:boolean;max?:number;placeholder?:string;help?:string}){
 const id=useId(),selected=presets.find(p=>p.value===value)?.value||'';
 return <div className="review-text-field"><label htmlFor={id}>{label}</label><select aria-label={`Suggested ${label.toLowerCase()}`} disabled={disabled} value={selected} onChange={e=>{if(e.target.value)onChange(e.target.value)}}><option value="">{value&&!selected?'Custom text · choose a suggestion to replace it':'Choose a suggestion or type your own below'}</option>{presets.map(p=><option key={p.value} value={p.value}>{p.label}</option>)}</select>{multiline?<textarea id={id} disabled={disabled} value={value} onChange={e=>onChange(e.target.value)} maxLength={max} rows={3} placeholder={placeholder}/>:<input id={id} disabled={disabled} value={value} onChange={e=>onChange(e.target.value)} maxLength={max} placeholder={placeholder}/>}<small>{help||'Suggestions are editable. Select only a statement supported by the evidence you reviewed.'}</small></div>
}
