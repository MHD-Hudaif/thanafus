(function(){let e=document.createElement(`link`).relList;if(e&&e.supports&&e.supports(`modulepreload`))return;for(let e of document.querySelectorAll(`link[rel="modulepreload"]`))n(e);new MutationObserver(e=>{for(let t of e)if(t.type===`childList`)for(let e of t.addedNodes)e.tagName===`LINK`&&e.rel===`modulepreload`&&n(e)}).observe(document,{childList:!0,subtree:!0});function t(e){let t={};return e.integrity&&(t.integrity=e.integrity),e.referrerPolicy&&(t.referrerPolicy=e.referrerPolicy),t.credentials=e.crossOrigin===`use-credentials`?`include`:e.crossOrigin===`anonymous`?`omit`:`same-origin`,t}function n(e){if(e.ep)return;e.ep=!0;let n=t(e);fetch(e.href,n)}})();var e=class{constructor(e){e||=window.location.pathname.includes(`/mobile-app/`)?window.location.origin+window.location.pathname.split(`/mobile-app/`)[0]:`https://musabaqa.kauzariyya.com`,this.apiBaseUrl=e.replace(/\/+$/,``),this.eventSource=null,this.pollingTimer=null,this.isConnected=!1,this.listeners={onStatusChange:[],onScoreUpdate:[],onActivityLog:[]}}connect(){let e=`${this.apiBaseUrl}/api/live-stream.php`;try{this.eventSource&&this.eventSource.close(),this.eventSource=new EventSource(e),this.eventSource.addEventListener(`connected`,e=>{JSON.parse(e.data),this.isConnected=!0,this.notifyStatus(`live`,`Live Socket Connected`)}),this.eventSource.addEventListener(`score_update`,e=>{let t=JSON.parse(e.data);this.isConnected=!0,this.notifyStatus(`live`,`Live Stream Active`),this.notifyScoreUpdate(t)}),this.eventSource.addEventListener(`error`,e=>{this.isConnected=!1,this.notifyStatus(`polling`,`Reconnecting... (Fallback Polling)`),this.startFallbackPolling()})}catch(e){console.warn(`SSE stream error, starting fallback polling`,e),this.startFallbackPolling()}}startFallbackPolling(){this.pollingTimer||=setInterval(async()=>{try{let e=await fetch(`${this.apiBaseUrl}/api/admin-scoreboard.php?t=${Date.now()}`);if(!e.ok)throw Error(`Polling failed`);let t=await e.json();t.ok&&this.notifyScoreUpdate({leaderboard:t.leaderboard,latest_update:t.latest_update,metrics:t.metrics,recent_activity:t.recent_activity})}catch{this.notifyStatus(`offline`,`Offline / Connection Retry`)}},3e3)}disconnect(){this.eventSource&&=(this.eventSource.close(),null),this.pollingTimer&&=(clearInterval(this.pollingTimer),null),this.isConnected=!1,this.notifyStatus(`offline`,`Disconnected`)}onStatusChange(e){this.listeners.onStatusChange.push(e)}onScoreUpdate(e){this.listeners.onScoreUpdate.push(e)}notifyStatus(e,t){this.listeners.onStatusChange.forEach(n=>n(e,t))}notifyScoreUpdate(e){this.listeners.onScoreUpdate.forEach(t=>t(e))}};function t(){return window.location.pathname.includes(`/mobile-app/`)?window.location.origin+window.location.pathname.split(`/mobile-app/`)[0]:`https://musabaqa.kauzariyya.com`}var n=`${t()}/api`;async function r(){try{let e=await fetch(`${n}/admin-scoreboard.php?t=${Date.now()}`);if(!e.ok)throw Error(`HTTP error! status: ${e.status}`);return await e.json()}catch(e){return console.error(`Failed to fetch admin scoreboard:`,e),{ok:!1,error:e.message}}}var i;(function(e){e.Unimplemented=`UNIMPLEMENTED`,e.Unavailable=`UNAVAILABLE`})(i||={});var a=class extends Error{constructor(e,t,n){super(e),this.message=e,this.code=t,this.data=n}},o=e=>e?.androidBridge?`android`:e?.webkit?.messageHandlers?.bridge?`ios`:`web`,s=e=>{let t=e.CapacitorCustomPlatform||null,n=e.Capacitor||{},r=n.Plugins=n.Plugins||{},s=()=>t===null?o(e):t.name,c=()=>s()!==`web`,l=e=>!!(f.get(e)?.platforms.has(s())||u(e)),u=e=>n.PluginHeaders?.find(t=>t.name===e),d=t=>e.console.error(t),f=new Map;return n.convertFileSrc||=e=>e,n.getPlatform=s,n.handleError=d,n.isNativePlatform=c,n.isPluginAvailable=l,n.registerPlugin=(e,o={})=>{let c=f.get(e);if(c)return console.warn(`Capacitor plugin "${e}" already registered. Cannot register plugins twice.`),c.proxy;let l=s(),d=u(e),p,m=async()=>(!p&&l in o?p=p=typeof o[l]==`function`?await o[l]():o[l]:t!==null&&!p&&`web`in o&&(p=p=typeof o.web==`function`?await o.web():o.web),p),h=(t,r)=>{if(d){let i=d?.methods.find(e=>r===e.name);if(i)return i.rtype===`promise`?t=>n.nativePromise(e,r.toString(),t):(t,i)=>n.nativeCallback(e,r.toString(),t,i);if(t)return t[r]?.bind(t)}else if(t)return t[r]?.bind(t);else throw new a(`"${e}" plugin is not implemented on ${l}`,i.Unimplemented)},g=t=>{let n,r=(...r)=>{let o=m().then(o=>{let s=h(o,t);if(s){let e=s(...r);return n=e?.remove,e}throw new a(`"${e}.${t}()" is not implemented on ${l}`,i.Unimplemented)});return t===`addListener`&&(o.remove=async()=>n()),o};return r.toString=()=>`${t.toString()}() { [capacitor code] }`,Object.defineProperty(r,"name",{value:t,writable:!1,configurable:!1}),r},_=g(`addListener`),v=g(`removeListener`),y=(e,t)=>{let n=_({eventName:e},t),r=async()=>{let r=await n;v({eventName:e,callbackId:r},t)},i=new Promise(e=>n.then(()=>e({remove:r})));return i.remove=async()=>{console.warn(`Using addListener() without 'await' is deprecated.`),await r()},i},b=new Proxy({},{get(e,t){switch(t){case`$$typeof`:return;case`toJSON`:return()=>({});case`addListener`:return d?y:_;case`removeListener`:return v;default:return g(t)}}});return r[e]=b,f.set(e,{name:e,proxy:b,platforms:new Set([...Object.keys(o),...d?[l]:[]])}),b},n.Exception=a,n.DEBUG=!!n.DEBUG,n.isLoggingEnabled=!!n.isLoggingEnabled,n},c=(e=>e.Capacitor=s(e))(typeof globalThis<`u`?globalThis:typeof self<`u`?self:typeof window<`u`?window:typeof global<`u`?global:{}),l=c.registerPlugin,u=class{constructor(){this.listeners={},this.retainedEventArguments={},this.windowListeners={}}addListener(e,t){let n=!1;this.listeners[e]||(this.listeners[e]=[],n=!0),this.listeners[e].push(t);let r=this.windowListeners[e];return r&&!r.registered&&this.addWindowListener(r),n&&this.sendRetainedArgumentsForEvent(e),Promise.resolve({remove:async()=>this.removeListener(e,t)})}async removeAllListeners(){this.listeners={};for(let e in this.windowListeners)this.removeWindowListener(this.windowListeners[e]);this.windowListeners={}}notifyListeners(e,t,n){let r=this.listeners[e];if(!r){if(n){let n=this.retainedEventArguments[e];n||=[],n.push(t),this.retainedEventArguments[e]=n}return}r.forEach(e=>e(t))}hasListeners(e){return!!this.listeners[e]?.length}registerWindowListener(e,t){this.windowListeners[t]={registered:!1,windowEventName:e,pluginEventName:t,handler:e=>{this.notifyListeners(t,e)}}}unimplemented(e=`not implemented`){return new c.Exception(e,i.Unimplemented)}unavailable(e=`not available`){return new c.Exception(e,i.Unavailable)}async removeListener(e,t){let n=this.listeners[e];if(!n)return;let r=n.indexOf(t);this.listeners[e].splice(r,1),this.listeners[e].length||this.removeWindowListener(this.windowListeners[e])}addWindowListener(e){window.addEventListener(e.windowEventName,e.handler),e.registered=!0}removeWindowListener(e){e&&(window.removeEventListener(e.windowEventName,e.handler),e.registered=!1)}sendRetainedArgumentsForEvent(e){let t=this.retainedEventArguments[e];t&&(delete this.retainedEventArguments[e],t.forEach(t=>{this.notifyListeners(e,t)}))}},d=e=>encodeURIComponent(e).replace(/%(2[346B]|5E|60|7C)/g,decodeURIComponent).replace(/[()]/g,escape),f=e=>e.replace(/(%[\dA-F]{2})+/gi,decodeURIComponent),p=class extends u{async getCookies(){let e=document.cookie,t={};return e.split(`;`).forEach(e=>{if(e.length<=0)return;let[n,r]=e.replace(/=/,`CAP_COOKIE`).split(`CAP_COOKIE`);n=f(n).trim(),r=f(r).trim(),t[n]=r}),t}async setCookie(e){try{let t=d(e.key),n=d(e.value),r=e.expires?`; expires=${e.expires.replace(`expires=`,``)}`:``,i=(e.path||`/`).replace(`path=`,``),a=e.url!=null&&e.url.length>0?`domain=${e.url}`:``;document.cookie=`${t}=${n||``}${r}; path=${i}; ${a};`}catch(e){return Promise.reject(e)}}async deleteCookie(e){try{document.cookie=`${e.key}=; Max-Age=0`}catch(e){return Promise.reject(e)}}async clearCookies(){try{let e=document.cookie.split(`;`)||[];for(let t of e)document.cookie=t.replace(/^ +/,``).replace(/=.*/,`=;expires=${new Date().toUTCString()};path=/`)}catch(e){return Promise.reject(e)}}async clearAllCookies(){try{await this.clearCookies()}catch(e){return Promise.reject(e)}}};l(`CapacitorCookies`,{web:()=>new p});var m=async e=>new Promise((t,n)=>{let r=new FileReader;r.onload=()=>{let e=r.result;t(e.indexOf(`,`)>=0?e.split(`,`)[1]:e)},r.onerror=e=>n(e),r.readAsDataURL(e)}),h=(e={})=>{let t=Object.keys(e);return Object.keys(e).map(e=>e.toLocaleLowerCase()).reduce((n,r,i)=>(n[r]=e[t[i]],n),{})},g=(e,t=!0)=>e?Object.entries(e).reduce((e,n)=>{let[r,i]=n,a,o;return Array.isArray(i)?(o=``,i.forEach(e=>{a=t?encodeURIComponent(e):e,o+=`${r}=${a}&`}),o.slice(0,-1)):(a=t?encodeURIComponent(i):i,o=`${r}=${a}`),`${e}&${o}`},``).substr(1):null,_=(e,t={})=>{let n=Object.assign({method:e.method||`GET`,headers:e.headers},t),r=h(e.headers)[`content-type`]||``;if(typeof e.data==`string`)n.body=e.data;else if(r.includes(`application/x-www-form-urlencoded`)){let t=new URLSearchParams;for(let[n,r]of Object.entries(e.data||{}))t.set(n,r);n.body=t.toString()}else if(r.includes(`multipart/form-data`)||e.data instanceof FormData){let t=new FormData;if(e.data instanceof FormData)e.data.forEach((e,n)=>{t.append(n,e)});else for(let n of Object.keys(e.data))t.append(n,e.data[n]);n.body=t;let r=new Headers(n.headers);r.delete(`content-type`),n.headers=r}else(r.includes(`application/json`)||typeof e.data==`object`)&&(n.body=JSON.stringify(e.data));return n},v=class extends u{async request(e){let t=_(e,e.webFetchExtra),n=g(e.params,e.shouldEncodeUrlParams),r=n?`${e.url}?${n}`:e.url,i=await fetch(r,t),a=i.headers.get(`content-type`)||``,{responseType:o=`text`}=i.ok?e:{};a.includes(`application/json`)&&(o=`json`);let s,c;switch(o){case`arraybuffer`:case`blob`:c=await i.blob(),s=await m(c);break;case`json`:s=await i.json();break;default:s=await i.text()}let l={};return i.headers.forEach((e,t)=>{l[t]=e}),{data:s,headers:l,status:i.status,url:i.url}}async get(e){return this.request(Object.assign(Object.assign({},e),{method:`GET`}))}async post(e){return this.request(Object.assign(Object.assign({},e),{method:`POST`}))}async put(e){return this.request(Object.assign(Object.assign({},e),{method:`PUT`}))}async patch(e){return this.request(Object.assign(Object.assign({},e),{method:`PATCH`}))}async delete(e){return this.request(Object.assign(Object.assign({},e),{method:`DELETE`}))}};l(`CapacitorHttp`,{web:()=>new v});var y;(function(e){e.Dark=`DARK`,e.Light=`LIGHT`,e.Default=`DEFAULT`})(y||={});var b;(function(e){e.StatusBar=`StatusBar`,e.NavigationBar=`NavigationBar`})(b||={});var x=class extends u{async setStyle(){this.unavailable(`not available for web`)}async setAnimation(){this.unavailable(`not available for web`)}async show(){this.unavailable(`not available for web`)}async hide(){this.unavailable(`not available for web`)}};l(`SystemBars`,{web:()=>new x});var ee=`modulepreload`,S=function(e,t){return new URL(e,t).href},C={},w=function(e,t,n){let r=Promise.resolve();if(t&&t.length>0){let e=document.getElementsByTagName(`link`),i=document.querySelector(`meta[property=csp-nonce]`),a=i?.nonce||i?.getAttribute(`nonce`);function o(e){return Promise.all(e.map(e=>Promise.resolve(e).then(e=>({status:`fulfilled`,value:e}),e=>({status:`rejected`,reason:e}))))}function s(e){return import.meta.resolve?import.meta.resolve(e):new URL(e,import.meta.url).href}r=o(t.map(t=>{if(t=S(t,n),t=s(t),t in C)return;C[t]=!0;let r=t.endsWith(`.css`);for(let n=e.length-1;n>=0;n--){let i=e[n];if(i.href===t&&(!r||i.rel===`stylesheet`))return}let i=document.createElement(`link`);if(i.rel=r?`stylesheet`:ee,r||(i.as=`script`),i.crossOrigin=``,i.href=t,a&&i.setAttribute(`nonce`,a),document.head.appendChild(i),r)return new Promise((e,n)=>{i.addEventListener(`load`,e),i.addEventListener(`error`,()=>n(Error(`Unable to preload CSS for ${t}`)))})}))}function i(e){let t=new Event(`vite:preloadError`,{cancelable:!0});if(t.payload=e,window.dispatchEvent(t),!t.defaultPrevented)throw e}return r.then(t=>{for(let e of t||[])e.status===`rejected`&&i(e.reason);return e().catch(i)})},T=l(`ScreenOrientation`,{web:()=>w(()=>import(`./web-BGYYk__X.js`).then(e=>new e.ScreenOrientationWeb),[],import.meta.url)}),E=l(`App`,{web:()=>w(()=>import(`./web-B4tRY4g_.js`).then(e=>new e.AppWeb),[],import.meta.url)}),te=`__capgo_keep_url_path_after_reload`,D=`__capgo_history_stack__`,O=100;if(typeof window<`u`&&typeof document<`u`&&typeof history<`u`){let e=window;if(!e.__capgoHistoryPatched){e.__capgoHistoryPatched=!0;let t=()=>{try{if(e.__capgoKeepUrlPathAfterReload)return!0}catch{}try{return window.localStorage.getItem(te)===`1`}catch{return!1}},n=()=>{try{let e=window.sessionStorage.getItem(D);if(!e)return{stack:[],index:-1};let t=JSON.parse(e);return!t||!Array.isArray(t.stack)||typeof t.index!=`number`?{stack:[],index:-1}:t}catch{return{stack:[],index:-1}}},r=(e,t)=>{try{window.sessionStorage.setItem(D,JSON.stringify({stack:e,index:t}))}catch{}},i=()=>{try{window.sessionStorage.removeItem(D)}catch{}},a=e=>{try{let t=e??window.location.href,n=new URL(t instanceof URL?t.toString():t,window.location.href);return`${n.pathname}${n.search}${n.hash}`}catch{return null}},o=(e,t)=>{if(e.length<=O)return{stack:e,index:t};let n=e.length-O;return{stack:e.slice(n),index:Math.max(0,t-n)}},s=e=>{document.readyState===`complete`||document.readyState===`interactive`?e():window.addEventListener(`DOMContentLoaded`,e,{once:!0})},c=!1,l=!1,u=!1,d=()=>{if(!c)return;let e=n(),t=a();if(t){if(e.stack.length===0){e.stack.push(t),e.index=0,r(e.stack,e.index);return}(e.index<0||e.index>=e.stack.length)&&(e.index=e.stack.length-1),e.stack[e.index]!==t&&(e.stack[e.index]=t,r(e.stack,e.index))}},f=(e,t)=>{if(!c||l)return;let i=a(e);if(!i)return;let{stack:s,index:u}=n();s.length===0?(s.push(i),u=s.length-1):t?((u<0||u>=s.length)&&(u=s.length-1),s[u]=i):u>=s.length-1?(s.push(i),u=s.length-1):(s=s.slice(0,u+1),s.push(i),u=s.length-1),{stack:s,index:u}=o(s,u),r(s,u)},p=()=>{if(!c||l)return;let e=n();if(e.stack.length===0){d();return}let t=e.index>=0&&e.index<e.stack.length?e.index:e.stack.length-1,r=a();if(e.stack.length===1&&r===e.stack[0])return;let i=e.stack[0];if(!i)return;l=!0;try{history.replaceState(history.state,document.title,i);for(let t=1;t<e.stack.length;t+=1)history.pushState(history.state,document.title,e.stack[t])}catch{l=!1;return}l=!1;let o=t-(e.stack.length-1);o===0?(history.replaceState(history.state,document.title,e.stack[t]),window.dispatchEvent(new PopStateEvent(`popstate`))):history.go(o)},m=()=>{!c||u||(u=!0,s(()=>{u=!1,p()}))},h=null,g=null,_=()=>{if(!c||l)return;let e=a();if(!e)return;let t=n(),i=t.stack.lastIndexOf(e);i>=0?t.index=i:(t.stack.push(e),t.index=t.stack.length-1);let s=o(t.stack,t.index);r(s.stack,s.index)},v=()=>{h&&g||(h=history.pushState,g=history.replaceState,history.pushState=function(e,t,n){let r=h?.call(history,e,t,n);return f(n,!1),r},history.replaceState=function(e,t,n){let r=g?.call(history,e,t,n);return f(n,!0),r},window.addEventListener(`popstate`,_))},y=()=>{h&&=(history.pushState=h,null),g&&=(history.replaceState=g,null),window.removeEventListener(`popstate`,_)},b=e=>{if(c===e){c&&(d(),m());return}c=e,c?(v(),d(),m()):(y(),i())};window.addEventListener(`CapacitorUpdaterKeepUrlPathAfterReload`,t=>{let n=t?.detail?.enabled;typeof n==`boolean`?(e.__capgoKeepUrlPathAfterReload=n,b(n)):(e.__capgoKeepUrlPathAfterReload=!0,b(!0))}),b(t())}}var k;(function(e){e[e.UNKNOWN=0]=`UNKNOWN`,e[e.UPDATE_NOT_AVAILABLE=1]=`UPDATE_NOT_AVAILABLE`,e[e.UPDATE_AVAILABLE=2]=`UPDATE_AVAILABLE`,e[e.UPDATE_IN_PROGRESS=3]=`UPDATE_IN_PROGRESS`})(k||={});var A;(function(e){e[e.UNKNOWN=0]=`UNKNOWN`,e[e.PENDING=1]=`PENDING`,e[e.DOWNLOADING=2]=`DOWNLOADING`,e[e.INSTALLING=3]=`INSTALLING`,e[e.INSTALLED=4]=`INSTALLED`,e[e.FAILED=5]=`FAILED`,e[e.CANCELED=6]=`CANCELED`,e[e.DOWNLOADED=11]=`DOWNLOADED`})(A||={});var j;(function(e){e[e.OK=0]=`OK`,e[e.CANCELED=1]=`CANCELED`,e[e.FAILED=2]=`FAILED`,e[e.NOT_AVAILABLE=3]=`NOT_AVAILABLE`,e[e.NOT_ALLOWED=4]=`NOT_ALLOWED`,e[e.INFO_MISSING=5]=`INFO_MISSING`})(j||={});var M=l(`CapacitorUpdater`,{web:()=>w(()=>import(`./web-nJW-2qXL.js`).then(e=>new e.CapacitorUpdaterWeb),[],import.meta.url)});window.addEventListener(`contextmenu`,e=>{let t=e.target;t&&(t.tagName===`INPUT`||t.tagName===`TEXTAREA`||t.isContentEditable)||e.preventDefault()});var N=`1.0.0`,P={view:`home`,updaterStatus:``,event:null,metrics:{total_programs:0,scheduled_programs:0,completed_programs:0},leaderboard:[],recentActivity:[],latestUpdate:null,activeTab:`standings`,socketStatus:{state:`connecting`,label:`Connecting...`},auth:{loggedIn:!0,phone:``,name:``,step:`phone`,error:``,info:``,maskedEmail:``,loading:!1}},F=new e,I=document.getElementById(`app`);function L(){return`
        <header class="mobile-header">
            <div class="header-brand">
                <button id="backToHomeBtn" class="back-btn" style="background: transparent; border: none; color: var(--text-main); font-size: 18px; cursor: pointer; padding-right: 8px; display: flex; align-items: center;" title="Back to Home">
                    <i class="fa-solid fa-arrow-left"></i>
                </button>
                <div class="brand-icon">
                    <i class="fa-solid fa-trophy"></i>
                </div>
                <div>
                    <div class="brand-title">Kauzariyya Musabaqa</div>
                    <div class="brand-subtitle">${P.event?$(P.event.title):`Live Scoreboard`}</div>
                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <div class="socket-badge ${P.socketStatus.state}">
                    <span class="pulse-dot"></span>
                    <span>${P.socketStatus.label}</span>
                </div>
            </div>
        </header>
    `}function R(){let e=P.leaderboard.length>0?P.leaderboard[0]:null;return`
        <div class="metrics-row">
            <div class="metric-card">
                <div class="metric-val">${P.metrics.completed_programs} / ${P.metrics.total_programs}</div>
                <div class="metric-lbl">Completed</div>
            </div>
            <div class="metric-card">
                <div class="metric-val" style="color: var(--accent-amber);">
                    ${e?$(e.team_name):`—`}
                </div>
                <div class="metric-lbl">Leading Team</div>
            </div>
            <div class="metric-card">
                <div class="metric-val" style="color: var(--accent-cyan);">
                    ${e?Q(e.total_score):`0`}
                </div>
                <div class="metric-lbl">Top Score</div>
            </div>
        </div>
    `}function z(){return!P.leaderboard||P.leaderboard.length===0?`<div class="empty-state"><i class="fa-solid fa-medal mr-2"></i> No leaderboard data recorded yet.</div>`:P.leaderboard.map((e,t)=>{let n=e.rank||t+1,r=e.color_code||`var(--accent-indigo)`,i=e.divisions||{},a=Object.entries(i).map(([e,t])=>`
            <span class="div-pill">
                ${$(e)}: <strong>${Q(t.score)}</strong>
            </span>
        `).join(``);return`
            <div class="team-card" style="--team-color: ${$(r)};">
                <div class="team-top">
                    <div class="team-left">
                        <div class="rank-badge rank-${n}">#${n}</div>
                        <div class="team-name">${$(e.team_name)}</div>
                    </div>
                    <div class="score-badge">
                        ${Q(e.total_score)}
                        <span class="score-unit">pts</span>
                    </div>
                </div>
                ${a?`<div class="division-pills">${a}</div>`:``}
            </div>
        `}).join(``)}function B(){return!P.recentActivity||P.recentActivity.length===0?`<div class="empty-state"><i class="fa-solid fa-bolt mr-2"></i> No recent score updates logged.</div>`:`
        <div class="activity-feed">
            ${P.recentActivity.map(e=>`
                <div class="activity-item">
                    <div class="activity-left">
                        <div class="activity-title">${$(e.program_title)}</div>
                        <div class="activity-sub">
                            <span style="color: ${$(e.color_code||`#6366f1`)}; font-weight: 700;">
                                ${$(e.team_name)}
                            </span>
                            · <span>${$(e.time_formatted||`Recently`)}</span>
                        </div>
                    </div>
                    <div class="activity-score">+${Q(e.score)} pts</div>
                </div>
            `).join(``)}
        </div>
    `}function V(){return`
        <nav class="bottom-nav">
            <button class="nav-btn ${P.activeTab===`standings`?`active`:``}" data-tab="standings">
                <i class="fa-solid fa-chart-simple nav-icon"></i>
                <span>Standings</span>
            </button>
            <button class="nav-btn ${P.activeTab===`activity`?`active`:``}" data-tab="activity">
                <i class="fa-solid fa-list-check nav-icon"></i>
                <span>Activity Feed</span>
            </button>
        </nav>
    `}async function H(){try{await T.lock({orientation:`landscape`})}catch(e){console.warn(`Native ScreenOrientation lock failed, trying standard API:`,e);try{screen.orientation&&screen.orientation.lock&&await screen.orientation.lock(`landscape`)}catch(e){console.warn(`Standard ScreenOrientation lock failed:`,e)}}}async function U(){try{await T.unlock()}catch(e){console.warn(`Native ScreenOrientation unlock failed, trying standard API:`,e);try{screen.orientation&&screen.orientation.unlock&&screen.orientation.unlock()}catch(e){console.warn(`Standard ScreenOrientation unlock failed:`,e)}}}function W(){P.view=`slideshow`,H(),Z()}function G(){P.view=`home`,U(),Z()}function K(){P.view=`scoreboard`,Z()}function q(){P.view=`home`,Z()}function J(){let e=``;if(P.updaterStatus){let t=``;P.updaterStatus===`checking`?t=`<i class="fa-solid fa-spinner fa-spin mr-2"></i> Checking for updates...`:P.updaterStatus===`downloading`?t=`<i class="fa-solid fa-cloud-arrow-down fa-bounce mr-2"></i> Downloading live update...`:P.updaterStatus===`error`&&(t=`<i class="fa-solid fa-circle-exclamation mr-2" style="color: var(--accent-rose);"></i> Update failed.`),e=`<div class="login-alert alert-info" style="margin-bottom: 16px; font-size: 12px; justify-content: center; padding: 8px 12px; font-weight: 600;">${t}</div>`}I.innerHTML=`
        <div class="home-container">
            <div class="home-card">
                <div class="home-brand">
                    <div class="brand-logo">
                        <i class="fa-solid fa-trophy"></i>
                    </div>
                    <h1>Kauzariyya Musabaqa</h1>
                    <p>Live Event Hub</p>
                </div>
                
                ${e}
                
                <div class="home-menu">
                    <button id="launchSlideshowBtn" class="menu-item-btn">
                        <div class="menu-item-icon">
                            <i class="fa-solid fa-tv"></i>
                        </div>
                        <div class="menu-item-content">
                            <span class="menu-item-title">Launch Slideshow</span>
                            <span class="menu-item-desc">Fullscreen presentation view (Auto-Landscape)</span>
                        </div>
                    </button>
                    
                    <button id="viewScoreboardBtn" class="menu-item-btn">
                        <div class="menu-item-icon">
                            <i class="fa-solid fa-chart-line"></i>
                        </div>
                        <div class="menu-item-content">
                            <span class="menu-item-title">Standings & Feed</span>
                            <span class="menu-item-desc">Check overall standings and real-time updates</span>
                        </div>
                    </button>
                </div>
                
                <div class="home-footer-status">
                    <div class="socket-badge ${P.socketStatus.state}">
                        <span class="pulse-dot"></span>
                        <span>${P.socketStatus.label}</span>
                    </div>
                </div>
            </div>
        </div>
    `,document.getElementById(`launchSlideshowBtn`).addEventListener(`click`,W),document.getElementById(`viewScoreboardBtn`).addEventListener(`click`,K)}function Y(){I.innerHTML=`
        <div class="slideshow-view">
            <button id="closeSlideshowBtn" class="slideshow-back-btn">
                <i class="fa-solid fa-arrow-left"></i>
                <span>Back to Home</span>
            </button>
            <iframe class="slideshow-iframe" src="${t()}/live-display/"></iframe>
        </div>
    `,document.getElementById(`closeSlideshowBtn`).addEventListener(`click`,G)}function X(){I.innerHTML=`
        ${L()}
        ${R()}
        
        <div class="section-header">
            <div class="section-title">
                <i class="fa-solid ${P.activeTab===`standings`?`fa-award`:`fa-clock-rotate-left`}" style="color: var(--accent-indigo);"></i>
                <span>${P.activeTab===`standings`?`Overall Team Standings`:`Live Score Activity`}</span>
            </div>
        </div>

        <main style="display: flex; flex-direction: column; gap: 10px;">
            ${P.activeTab===`standings`?z():B()}
        </main>

        ${V()}
    `,document.querySelectorAll(`.nav-btn`).forEach(e=>{e.addEventListener(`click`,()=>{P.activeTab=e.dataset.tab,X()})});let e=document.getElementById(`backToHomeBtn`);e&&e.addEventListener(`click`,q)}function Z(){P.view===`home`?J():P.view===`slideshow`?Y():P.view===`scoreboard`&&X()}function Q(e){return Math.round(Number(e)||0)}function $(e){return e?String(e).replace(/&/g,`&amp;`).replace(/</g,`&lt;`).replace(/>/g,`&gt;`).replace(/"/g,`&quot;`):``}async function ne(){let e=localStorage.getItem(`active_web_version`)||N;try{P.updaterStatus=`checking`,Z();let n=await fetch(`${t()}/uploads/app-web-version.json?t=${Date.now()}`);if(!n.ok)throw Error(`Failed to fetch version file`);let r=await n.json();if(r&&r.version&&r.version!==e&&r.url){P.updaterStatus=`downloading`,Z();let e=await M.download({url:r.url,version:r.version});await M.set({id:e.id}),localStorage.setItem(`active_web_version`,r.version),P.updaterStatus=``,Z(),window.location.reload()}else P.updaterStatus=``,Z()}catch(e){console.warn(`OTA update failed:`,e),P.updaterStatus=`error`,Z(),setTimeout(()=>{P.updaterStatus=``,Z()},5e3)}}async function re(){let e=await r();e&&e.ok?(P.event=e.event,P.metrics=e.metrics,P.leaderboard=e.leaderboard||[],P.recentActivity=e.recent_activity||[],P.latestUpdate=e.latest_update,Z()):(P.socketStatus={state:`offline`,label:`Backend Unavailable`},Z()),F.isConnected||(F.onStatusChange((e,t)=>{P.socketStatus={state:e,label:t},Z()}),F.onScoreUpdate(e=>{e.leaderboard&&(P.leaderboard=e.leaderboard),e.metrics&&(P.metrics=e.metrics),e.recent_activity&&(P.recentActivity=e.recent_activity),Z()}),F.connect());try{E.addListener(`backButton`,()=>{P.view===`slideshow`?G():P.view===`scoreboard`?q():E.exitApp()})}catch(e){console.warn(`Native App back button listener not available:`,e)}ne()}re();export{u as n,k as t};