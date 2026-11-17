const chromium = require('@sparticuz/chromium').default;
const puppeteer = require('puppeteer-core');
(async()=>{
  const b=await puppeteer.launch({args:[...chromium.args,'--no-sandbox','--disable-gpu','--font-render-hinting=none'],executablePath:await chromium.executablePath(),headless:'shell',env:{...process.env,LD_LIBRARY_PATH:'/tmp/al2023/lib:'+(process.env.LD_LIBRARY_PATH||'')}});
  const p=await b.newPage();
  await p.setViewport({width:1568,height:712,deviceScaleFactor:1});
  await p.goto('file:///tmp/lab/rtl.html',{waitUntil:'networkidle0'});
  await p.evaluate(()=>document.fonts.ready);
  await new Promise(r=>setTimeout(r,900));
  const o=await p.evaluate(()=>{
    const g=s=>{const e=document.querySelector(s);if(!e)return null;const r=e.getBoundingClientRect();return [Math.round(r.x),Math.round(r.y),Math.round(r.width),Math.round(r.height)];};
    const gp=document.querySelector('.ifg__groups'); const cs=getComputedStyle(gp);
    return {preview:g('.ifg__preview'),groups:g('.ifg__groups'),title:g('.ifg__title'),desc:g('.ifg__desc'),buy:g('.ifg__buy'),thumbs:g('.ifg__thumbnails'),
      padLeft:cs.paddingLeft,padRight:cs.paddingRight,padStart:cs.paddingInlineStart};
  });
  console.log(JSON.stringify(o));
  await p.screenshot({path:'/tmp/lab/rtl.png'});
  await b.close();
})();
