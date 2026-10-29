type Block = {top:number;bottom:number};
/** Prefer complete rows/sections at each page boundary; oversized blocks still make forward progress. */
export function documentPageRanges(height:number,pageHeight:number,blocks:Block[]):[number,number][]{
 if(!Number.isFinite(height)||!Number.isFinite(pageHeight)||height<=0||pageHeight<=0)throw new Error('Invalid document page size.');
 const pages:[number,number][]=[];let start=0;
 while(start<height){let end=Math.min(height,start+pageHeight);let changed=true;
  while(changed){changed=false;for(const block of blocks){if(block.top>start+.5&&block.top<end-.5&&block.bottom>end+.5&&block.bottom-block.top<=pageHeight){end=block.top;changed=true;}}}
  pages.push([start,end]);start=end;
 }
 return pages;
}
/** Render the source representation with real page breaks, never substitute financial fields. */
export async function downloadDocumentPdf(node:HTMLElement|null,number:string){
 if(!node)throw new Error('Document preview is not available.');
 await document.fonts.ready;
 await Promise.all([...node.querySelectorAll('img')].map(img=>img.decode().catch(()=>undefined)));
 const [{toPng},{jsPDF}]=await Promise.all([import('html-to-image'),import('jspdf')]);
 const image=await toPng(node,{pixelRatio:2,backgroundColor:'#ffffff',cacheBust:false});
 const pdf=new jsPDF({unit:'mm',format:'a4',compress:true});const width=200,mmPerPixel=width/node.offsetWidth,pageHeight=289/mmPerPixel;
 const origin=node.getBoundingClientRect();
 const blocks=[...node.querySelectorAll('.or-header,.or-source-notice,.or-parties,.or-buyer,.or-tax-warning,thead,tbody tr,.or-financials,.or-tax-breakdown,.or-validation-row,.or-source-note,.or-skyline,.or-footer')].map(el=>{const r=el.getBoundingClientRect();return{top:r.top-origin.top,bottom:r.bottom-origin.top};});
 const bitmap=new Image();bitmap.src=image;await bitmap.decode();const scale=bitmap.naturalWidth/node.offsetWidth;
 const ranges=documentPageRanges(node.scrollHeight,pageHeight,blocks);
 for(const [i,[from,to]] of ranges.entries()){
  if(i)pdf.addPage();const canvas=document.createElement('canvas');canvas.width=bitmap.naturalWidth;canvas.height=Math.ceil((to-from)*scale);
  const context=canvas.getContext('2d');if(!context)throw new Error('PDF image rendering is unavailable.');context.fillStyle='#ffffff';context.fillRect(0,0,canvas.width,canvas.height);
  context.drawImage(bitmap,0,from*scale,bitmap.naturalWidth,(to-from)*scale,0,0,canvas.width,canvas.height);
  pdf.addImage(canvas.toDataURL('image/png'),'PNG',5,5,width,(to-from)*mmPerPixel);
  pdf.setFontSize(8);pdf.setTextColor(80);pdf.text(`${i+1} / ${ranges.length}`,205,295,{align:'right'});
 }
 pdf.save(`${number.replace(/[^a-zA-Z0-9_-]/g,'-')}.pdf`);
}
