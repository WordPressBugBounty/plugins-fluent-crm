(()=>{var t={73:(t,e)=>{var r;!function(){"use strict";var n={}.hasOwnProperty;function o(){for(var t="",e=0;e<arguments.length;e++){var r=arguments[e];r&&(t=a(t,i(r)))}return t}function i(t){if("string"==typeof t||"number"==typeof t)return t;if("object"!=typeof t)return"";if(Array.isArray(t))return o.apply(null,t);if(t.toString!==Object.prototype.toString&&!t.toString.toString().includes("[native code]"))return t.toString();var e="";for(var r in t)n.call(t,r)&&t[r]&&(e=a(e,r));return e}function a(t,e){return e?t?t+" "+e:t+e:t}t.exports?(o.default=o,t.exports=o):void 0===(r=function(){return o}.apply(e,[]))||(t.exports=r)}()}},e={};function r(n){var o=e[n];if(void 0!==o)return o.exports;var i=e[n]={exports:{}};return t[n](i,i.exports,r),i.exports}r.n=t=>{var e=t&&t.__esModule?()=>t.default:()=>t;return r.d(e,{a:e}),e},r.d=(t,e)=>{for(var n in e)r.o(e,n)&&!r.o(t,n)&&Object.defineProperty(t,n,{enumerable:!0,get:e[n]})},r.o=(t,e)=>Object.prototype.hasOwnProperty.call(t,e),(()=>{"use strict";var t=["colorScheme","contentMaxWidth","children"];function e(){return e=Object.assign?Object.assign.bind():function(t){for(var e=1;e<arguments.length;e++){var r=arguments[e];for(var n in r)({}).hasOwnProperty.call(r,n)&&(t[n]=r[n])}return t},e.apply(null,arguments)}const n=function(r){r.colorScheme,r.contentMaxWidth;var n=r.children,o=function(t,e){if(null==t)return{};var r,n,o=function(t,e){if(null==t)return{};var r={};for(var n in t)if({}.hasOwnProperty.call(t,n)){if(e.includes(n))continue;r[n]=t[n]}return r}(t,e);if(Object.getOwnPropertySymbols){var i=Object.getOwnPropertySymbols(t);for(n=0;n<i.length;n++)r=i[n],e.includes(r)||{}.propertyIsEnumerable.call(t,r)&&(o[r]=t[r])}return o}(r,t);return React.createElement("div",e({className:"fc-cond-section"},o),React.createElement("div",{className:"fc-cond-blocks"},n))};var o=r(73),i=r.n(o);const a=window.wp.element,c=window.wp.components,l=window.wp.blockEditor,u=window.wp.blocks;wp.element.createElement;var s={};s.fluentcrm=React.createElement("svg",{width:"100%",height:"100%",viewBox:"0 0 300 300",version:"1.1",xmlns:"http://www.w3.org/2000/svg"},React.createElement("path",{fill:"#7743e6",d:"M300,30c0,-16.557 -13.443,-30 -30,-30l-240,0c-16.557,0 -30,13.443 -30,30l0,240c0,16.557 13.443,30 30,30l240,0c16.557,0 30,-13.443 30,-30l0,-240Z"}),React.createElement("g",null,React.createElement("path",{d:"M250.955,71.122c0,-0 -129.408,34.674 -181.023,48.505c-12.32,3.301 -20.887,14.465 -20.887,27.22c-0,9.696 -0,18.989 -0,18.989c-0,0 103.954,-27.854 162.681,-43.59c23.139,-6.2 39.229,-27.169 39.229,-51.124c0,-0 0,-0 0,-0Z",fill:"white"}),React.createElement("path",{d:"M173.46,154.928c-0,0 -68.092,18.246 -103.528,27.741c-12.32,3.301 -20.887,14.465 -20.887,27.22c-0,9.696 -0,18.989 -0,18.989c-0,0 48.721,-13.054 85.185,-22.825c23.14,-6.2 39.23,-27.169 39.23,-51.124c-0,-0.001 -0,-0.001 -0,-0.001Z",fill:"white"})));const f=s;function h(t){return h="function"==typeof Symbol&&"symbol"==typeof Symbol.iterator?function(t){return typeof t}:function(t){return t&&"function"==typeof Symbol&&t.constructor===Symbol&&t!==Symbol.prototype?"symbol":typeof t},h(t)}function p(){p=function(){return e};var t,e={},r=Object.prototype,n=r.hasOwnProperty,o=Object.defineProperty||function(t,e,r){t[e]=r.value},i="function"==typeof Symbol?Symbol:{},a=i.iterator||"@@iterator",c=i.asyncIterator||"@@asyncIterator",l=i.toStringTag||"@@toStringTag";function u(t,e,r){return Object.defineProperty(t,e,{value:r,enumerable:!0,configurable:!0,writable:!0}),t[e]}try{u({},"")}catch(t){u=function(t,e,r){return t[e]=r}}function s(t,e,r,n){var i=e&&e.prototype instanceof w?e:w,a=Object.create(i.prototype),c=new I(n||[]);return o(a,"_invoke",{value:R(t,r,c)}),a}function f(t,e,r){try{return{type:"normal",arg:t.call(e,r)}}catch(t){return{type:"throw",arg:t}}}e.wrap=s;var d="suspendedStart",y="suspendedYield",v="executing",g="completed",m={};function w(){}function b(){}function _(){}var x={};u(x,a,(function(){return this}));var E=Object.getPrototypeOf,S=E&&E(E(N([])));S&&S!==r&&n.call(S,a)&&(x=S);var O=_.prototype=w.prototype=Object.create(x);function L(t){["next","throw","return"].forEach((function(e){u(t,e,(function(t){return this._invoke(e,t)}))}))}function j(t,e){function r(o,i,a,c){var l=f(t[o],t,i);if("throw"!==l.type){var u=l.arg,s=u.value;return s&&"object"==h(s)&&n.call(s,"__await")?e.resolve(s.__await).then((function(t){r("next",t,a,c)}),(function(t){r("throw",t,a,c)})):e.resolve(s).then((function(t){u.value=t,a(u)}),(function(t){return r("throw",t,a,c)}))}c(l.arg)}var i;o(this,"_invoke",{value:function(t,n){function o(){return new e((function(e,o){r(t,n,e,o)}))}return i=i?i.then(o,o):o()}})}function R(e,r,n){var o=d;return function(i,a){if(o===v)throw Error("Generator is already running");if(o===g){if("throw"===i)throw a;return{value:t,done:!0}}for(n.method=i,n.arg=a;;){var c=n.delegate;if(c){var l=k(c,n);if(l){if(l===m)continue;return l}}if("next"===n.method)n.sent=n._sent=n.arg;else if("throw"===n.method){if(o===d)throw o=g,n.arg;n.dispatchException(n.arg)}else"return"===n.method&&n.abrupt("return",n.arg);o=v;var u=f(e,r,n);if("normal"===u.type){if(o=n.done?g:y,u.arg===m)continue;return{value:u.arg,done:n.done}}"throw"===u.type&&(o=g,n.method="throw",n.arg=u.arg)}}}function k(e,r){var n=r.method,o=e.iterator[n];if(o===t)return r.delegate=null,"throw"===n&&e.iterator.return&&(r.method="return",r.arg=t,k(e,r),"throw"===r.method)||"return"!==n&&(r.method="throw",r.arg=new TypeError("The iterator does not provide a '"+n+"' method")),m;var i=f(o,e.iterator,r.arg);if("throw"===i.type)return r.method="throw",r.arg=i.arg,r.delegate=null,m;var a=i.arg;return a?a.done?(r[e.resultName]=a.value,r.next=e.nextLoc,"return"!==r.method&&(r.method="next",r.arg=t),r.delegate=null,m):a:(r.method="throw",r.arg=new TypeError("iterator result is not an object"),r.delegate=null,m)}function P(t){var e={tryLoc:t[0]};1 in t&&(e.catchLoc=t[1]),2 in t&&(e.finallyLoc=t[2],e.afterLoc=t[3]),this.tryEntries.push(e)}function T(t){var e=t.completion||{};e.type="normal",delete e.arg,t.completion=e}function I(t){this.tryEntries=[{tryLoc:"root"}],t.forEach(P,this),this.reset(!0)}function N(e){if(e||""===e){var r=e[a];if(r)return r.call(e);if("function"==typeof e.next)return e;if(!isNaN(e.length)){var o=-1,i=function r(){for(;++o<e.length;)if(n.call(e,o))return r.value=e[o],r.done=!1,r;return r.value=t,r.done=!0,r};return i.next=i}}throw new TypeError(h(e)+" is not iterable")}return b.prototype=_,o(O,"constructor",{value:_,configurable:!0}),o(_,"constructor",{value:b,configurable:!0}),b.displayName=u(_,l,"GeneratorFunction"),e.isGeneratorFunction=function(t){var e="function"==typeof t&&t.constructor;return!!e&&(e===b||"GeneratorFunction"===(e.displayName||e.name))},e.mark=function(t){return Object.setPrototypeOf?Object.setPrototypeOf(t,_):(t.__proto__=_,u(t,l,"GeneratorFunction")),t.prototype=Object.create(O),t},e.awrap=function(t){return{__await:t}},L(j.prototype),u(j.prototype,c,(function(){return this})),e.AsyncIterator=j,e.async=function(t,r,n,o,i){void 0===i&&(i=Promise);var a=new j(s(t,r,n,o),i);return e.isGeneratorFunction(r)?a:a.next().then((function(t){return t.done?t.value:a.next()}))},L(O),u(O,l,"Generator"),u(O,a,(function(){return this})),u(O,"toString",(function(){return"[object Generator]"})),e.keys=function(t){var e=Object(t),r=[];for(var n in e)r.push(n);return r.reverse(),function t(){for(;r.length;){var n=r.pop();if(n in e)return t.value=n,t.done=!1,t}return t.done=!0,t}},e.values=N,I.prototype={constructor:I,reset:function(e){if(this.prev=0,this.next=0,this.sent=this._sent=t,this.done=!1,this.delegate=null,this.method="next",this.arg=t,this.tryEntries.forEach(T),!e)for(var r in this)"t"===r.charAt(0)&&n.call(this,r)&&!isNaN(+r.slice(1))&&(this[r]=t)},stop:function(){this.done=!0;var t=this.tryEntries[0].completion;if("throw"===t.type)throw t.arg;return this.rval},dispatchException:function(e){if(this.done)throw e;var r=this;function o(n,o){return c.type="throw",c.arg=e,r.next=n,o&&(r.method="next",r.arg=t),!!o}for(var i=this.tryEntries.length-1;i>=0;--i){var a=this.tryEntries[i],c=a.completion;if("root"===a.tryLoc)return o("end");if(a.tryLoc<=this.prev){var l=n.call(a,"catchLoc"),u=n.call(a,"finallyLoc");if(l&&u){if(this.prev<a.catchLoc)return o(a.catchLoc,!0);if(this.prev<a.finallyLoc)return o(a.finallyLoc)}else if(l){if(this.prev<a.catchLoc)return o(a.catchLoc,!0)}else{if(!u)throw Error("try statement without catch or finally");if(this.prev<a.finallyLoc)return o(a.finallyLoc)}}}},abrupt:function(t,e){for(var r=this.tryEntries.length-1;r>=0;--r){var o=this.tryEntries[r];if(o.tryLoc<=this.prev&&n.call(o,"finallyLoc")&&this.prev<o.finallyLoc){var i=o;break}}i&&("break"===t||"continue"===t)&&i.tryLoc<=e&&e<=i.finallyLoc&&(i=null);var a=i?i.completion:{};return a.type=t,a.arg=e,i?(this.method="next",this.next=i.finallyLoc,m):this.complete(a)},complete:function(t,e){if("throw"===t.type)throw t.arg;return"break"===t.type||"continue"===t.type?this.next=t.arg:"return"===t.type?(this.rval=this.arg=t.arg,this.method="return",this.next="end"):"normal"===t.type&&e&&(this.next=e),m},finish:function(t){for(var e=this.tryEntries.length-1;e>=0;--e){var r=this.tryEntries[e];if(r.finallyLoc===t)return this.complete(r.completion,r.afterLoc),T(r),m}},catch:function(t){for(var e=this.tryEntries.length-1;e>=0;--e){var r=this.tryEntries[e];if(r.tryLoc===t){var n=r.completion;if("throw"===n.type){var o=n.arg;T(r)}return o}}throw Error("illegal catch attempt")},delegateYield:function(e,r,n){return this.delegate={iterator:N(e),resultName:r,nextLoc:n},"next"===this.method&&(this.arg=t),m}},e}function d(t,e,r,n,o,i,a){try{var c=t[i](a),l=c.value}catch(t){return void r(t)}c.done?e(l):Promise.resolve(l).then(n,o)}function y(t,e){(null==e||e>t.length)&&(e=t.length);for(var r=0,n=Array(e);r<e;r++)n[r]=t[r];return n}var v=wp.i18n,__=v.__,_x=v._x;(0,u.registerBlockType)("fluent-crm/conditional-content",{title:__("Conditional Section"),description:__("Add a conditional section that separates content, and put any other block into it. Show/hide this section based on visitors login state or available tags"),category:"layout",icon:f.fluentcrm,keywords:[_x("conditional"),_x("section")],supports:{align:["wide","full"],anchor:!0},attributes:{condition_type:{type:"string",default:"show_if_tag_exist"},tag_ids:{type:"array",default:[]}},edit:function(t){var e,r,o=t.attributes,i=t.setAttributes,u=o.condition_type,s=o.tag_ids,f=(e=(0,a.useState)([]),r=2,function(t){if(Array.isArray(t))return t}(e)||function(t,e){var r=null==t?null:"undefined"!=typeof Symbol&&t[Symbol.iterator]||t["@@iterator"];if(null!=r){var n,o,i,a,c=[],l=!0,u=!1;try{if(i=(r=r.call(t)).next,0===e){if(Object(r)!==r)return;l=!1}else for(;!(l=(n=i.call(r)).done)&&(c.push(n.value),c.length!==e);l=!0);}catch(t){u=!0,o=t}finally{try{if(!l&&null!=r.return&&(a=r.return(),Object(a)!==a))return}finally{if(u)throw o}}return c}}(e,r)||function(t,e){if(t){if("string"==typeof t)return y(t,e);var r={}.toString.call(t).slice(8,-1);return"Object"===r&&t.constructor&&(r=t.constructor.name),"Map"===r||"Set"===r?Array.from(t):"Arguments"===r||/^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(r)?y(t,e):void 0}}(e,r)||function(){throw new TypeError("Invalid attempt to destructure non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method.")}()),h=f[0],v=f[1];return(0,a.useEffect)((function(){function t(){var e;return e=p().mark((function t(){return p().wrap((function(t){for(;;)switch(t.prev=t.next){case 0:window._fcrm_available_tags?v(window._fcrm_available_tags):wp.apiFetch({path:"fluent-crm/v2/reports/options?fields=tags"}).then((function(t){window._fcrm_available_tags=t.options.tags,v(t.options.tags)}));case 1:case"end":return t.stop()}}),t)})),t=function(){var t=this,r=arguments;return new Promise((function(n,o){var i=e.apply(t,r);function a(t){d(i,n,o,a,c,"next",t)}function c(t){d(i,n,o,a,c,"throw",t)}a(void 0)}))},t.apply(this,arguments)}!function(){t.apply(this,arguments)}()}),[]),React.createElement(a.Fragment,null,React.createElement(l.InspectorControls,null,React.createElement(c.PanelBody,{title:__("Conditional Settings")},React.createElement(c.SelectControl,{label:__("Condition Type"),value:u,onChange:function(t){i({condition_type:t||"show_if_tag_exist"})},options:[{value:"show_if_tag_exist",label:"Show IF in selected tag"},{value:"show_if_tag_not_exist",label:"Show IF not in selected tag"},{value:"show_if_logged_in",label:"Show if user is logged in"},{value:"show_if_public_users",label:"Show if user is not logged in"}]}),"show_if_tag_exist"!=u&&"show_if_tag_not_exist"!=u&&u?"":React.createElement("div",{className:"fcrm-gb-multi-checkbox"},React.createElement("h4",null,"Select Targeted Tags"),React.createElement("ul",null,h.map((function(t){return React.createElement(c.ToggleControl,{value:t.id,label:t.title,key:t.id,checked:-1!=s.indexOf(t.id),onChange:function(e){var r,n,a;r=e,n=t.id,a=jQuery.extend(!0,[],o.tag_ids),r?-1==a.indexOf(n)&&(a.push(n),i({tag_ids:a})):(a.splice(a.indexOf(n),1),i({tag_ids:a}))}})})))),React.createElement("div",{className:"fc_cd_info"},React.createElement("hr",null),React.createElement("b",null,"Tips:"),React.createElement("ul",null,React.createElement("li",null,"This will show/hide only if any of the selected tags is matched."),React.createElement("li",{style:{backgroundColor:"#ffffd7"}},"The yellow background in the content is only for editor and to identify the conditional contents"))))),React.createElement(n,{colorScheme:u,contentMaxWidth:s},React.createElement(l.InnerBlocks,null)))},save:function(t){var e=t.attributes,r=e.colorScheme,o=e.contentMaxWidth,a=e.attachmentId;return React.createElement(n,{colorScheme:r,contentMaxWidth:o,className:i()(a&&"has-background-image-".concat(a))},React.createElement(l.InnerBlocks.Content,null))}})})()})();