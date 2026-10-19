/*! PlotBird Analytics tracker | cookieless | <2 KB | readable: tracker.src.js */
(function(){"use strict";
var S=document.getElementsByTagName("script"),T=document.currentScript||S[S.length-1];
if(!T)return;var ID=T.getAttribute("data-website-id");if(!ID)return;
var EP=T.getAttribute("data-endpoint")||(T.src||"").replace(/\/assets\/tracker\.js.*$/,"")+"/api/collect.php";
var LAST="";
function B(e){var w=window.screen?screen.width:0,h=window.screen?screen.height:0;
return JSON.stringify({w:ID,p:location.pathname+location.search,e:e||"",r:document.referrer||"",t:document.title||"",s:w+"x"+h,l:navigator.language||"",dnt:(navigator.doNotTrack||window.doNotTrack)==="1"?"1":"0"})}
function P(b){if(navigator.sendBeacon){try{if(navigator.sendBeacon(EP,b))return}catch(e){}}
try{fetch(EP,{method:"POST",body:b,headers:{"Content-Type":"text/plain;charset=UTF-8"},mode:"cors",credentials:"omit",keepalive:!0})}catch(e){}}
function PV(){var p=location.pathname+location.search;if(p===LAST)return;LAST=p;P(B(""))}
function TR(n){if(typeof n!="string")return;n=n.replace(/[^A-Za-z0-9_.:-]/g,"").slice(0,120);if(!n)return;P(B(n))}
window.plotbird=window.plotbird||{};window.plotbird.track=TR;
if(window.plotbird.q&&window.plotbird.q.length){var Q=window.plotbird.q;window.plotbird.q=[];
for(var i=0;i<Q.length;i++)TR(Q[i])}
["pushState","replaceState"].forEach(function(m){var f=history[m];if(typeof f!="function")return;
history[m]=function(){var r=f.apply(this,arguments);setTimeout(PV,0);return r}});
addEventListener("popstate",function(){setTimeout(PV,0)});
if(document.readyState==="complete")PV();else addEventListener("load",PV);
})();
