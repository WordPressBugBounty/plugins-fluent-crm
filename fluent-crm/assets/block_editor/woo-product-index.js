(()=>{var e={2703:(e,t)=>{var r;!function(){"use strict";var n={}.hasOwnProperty;function o(){for(var e=[],t=0;t<arguments.length;t++){var r=arguments[t];if(r){var l=typeof r;if("string"===l||"number"===l)e.push(r);else if(Array.isArray(r)){if(r.length){var c=o.apply(null,r);c&&e.push(c)}}else if("object"===l){if(r.toString!==Object.prototype.toString&&!r.toString.toString().includes("[native code]")){e.push(r.toString());continue}for(var a in r)n.call(r,a)&&r[a]&&e.push(a)}}}return e.join(" ")}e.exports?(o.default=o,e.exports=o):void 0===(r=function(){return o}.apply(t,[]))||(e.exports=r)}()}},t={};function r(n){var o=t[n];if(void 0!==o)return o.exports;var l=t[n]={exports:{}};return e[n](l,l.exports,r),l.exports}r.n=e=>{var t=e&&e.__esModule?()=>e.default:()=>e;return r.d(t,{a:t}),t},r.d=(e,t)=>{for(var n in t)r.o(t,n)&&!r.o(e,n)&&Object.defineProperty(e,n,{enumerable:!0,get:t[n]})},r.o=(e,t)=>Object.prototype.hasOwnProperty.call(e,t),(()=>{"use strict";function e(t){return e="function"==typeof Symbol&&"symbol"==typeof Symbol.iterator?function(e){return typeof e}:function(e){return e&&"function"==typeof Symbol&&e.constructor===Symbol&&e!==Symbol.prototype?"symbol":typeof e},e(t)}function t(e,t){var r=Object.keys(e);if(Object.getOwnPropertySymbols){var n=Object.getOwnPropertySymbols(e);t&&(n=n.filter((function(t){return Object.getOwnPropertyDescriptor(e,t).enumerable}))),r.push.apply(r,n)}return r}function n(e){for(var r=1;r<arguments.length;r++){var n=null!=arguments[r]?arguments[r]:{};r%2?t(Object(n),!0).forEach((function(t){o(e,t,n[t])})):Object.getOwnPropertyDescriptors?Object.defineProperties(e,Object.getOwnPropertyDescriptors(n)):t(Object(n)).forEach((function(t){Object.defineProperty(e,t,Object.getOwnPropertyDescriptor(n,t))}))}return e}function o(t,r,n){return(r=function(t){var r=function(t,r){if("object"!==e(t)||null===t)return t;var n=t[Symbol.toPrimitive];if(void 0!==n){var o=n.call(t,"string");if("object"!==e(o))return o;throw new TypeError("@@toPrimitive must return a primitive value.")}return String(t)}(t);return"symbol"===e(r)?r:String(r)}(r))in t?Object.defineProperty(t,r,{value:n,enumerable:!0,configurable:!0,writable:!0}):t[r]=n,t}function l(e,t){return function(e){if(Array.isArray(e))return e}(e)||function(e,t){var r=null==e?null:"undefined"!=typeof Symbol&&e[Symbol.iterator]||e["@@iterator"];if(null!=r){var n,o,_x,l,c=[],_n=!0,a=!1;try{if(_x=(r=r.call(e)).next,0===t){if(Object(r)!==r)return;_n=!1}else for(;!(_n=(n=_x.call(r)).done)&&(c.push(n.value),c.length!==t);_n=!0);}catch(e){a=!0,o=e}finally{try{if(!_n&&null!=r.return&&(l=r.return(),Object(l)!==l))return}finally{if(a)throw o}}return c}}(e,t)||function(e,t){if(e){if("string"==typeof e)return c(e,t);var r=Object.prototype.toString.call(e).slice(8,-1);return"Object"===r&&e.constructor&&(r=e.constructor.name),"Map"===r||"Set"===r?Array.from(e):"Arguments"===r||/^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(r)?c(e,t):void 0}}(e,t)||function(){throw new TypeError("Invalid attempt to destructure non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method.")}()}function c(e,t){(null==t||t>e.length)&&(t=e.length);for(var r=0,n=new Array(t);r<t;r++)n[r]=e[r];return n}var a=wp.blockEditor,i=a.InspectorControls,u=a.PanelColorSettings,__=(a.MediaUpload,a.MediaUploadCheck,wp.i18n.__),s=wp.element,p=s.useState,f=s.useEffect,m=wp.components,d=m.PanelBody,y=m.PanelRow,b=m.ToggleControl,g=m.SelectControl;m.Button;const h=function(e){var t=e.attributes,r=t.productId,o=t.showDescription,c=t.showPrice,a=t.template,s=t.backgroundColor,m=t.contentColor,h=t.pricingColor,v=e.setAttributes,w=l(p([]),2),E=w[0],S=w[1],O=l(p(!1),2),R=(O[0],O[1]),_=wp.apiFetch,j=wp.url.addQueryArgs;f((function(){P()}),[]);var P=function(e){_({path:j("wc/store/products",n({per_page:6},e))}).then((function(e){S(e)})).catch((function(e){R(!0)})).finally((function(){}))};return r?React.createElement(i,null,React.createElement(d,{title:__("Template Settings"),initialOpen:!0},React.createElement(y,null,React.createElement("div",{className:"fluent-singleProduct-template-settings"},React.createElement(g,{label:__("Select Product"),value:r,options:E.map((function(e){return{value:e.id.toString(),label:e.name}})),onChange:function(e){v({productId:e})}}),React.createElement(g,{label:__("Design Template"),value:a,options:[{value:"left",name:"Image Left"},{value:"top",name:"Image Top"},{value:"none",name:"No Image"}].map((function(e){return{value:e.value,label:e.name}})),onChange:function(e){return v({template:e})}}),React.createElement(b,{label:__("Show Description"),checked:o,onChange:function(){return v({showDescription:!o})}}),React.createElement(b,{label:__("Show Price"),checked:c,onChange:function(){return v({showPrice:!c})}})))),React.createElement("div",{className:"fluent-singleProduct-titleAndSubtitle-settings"},React.createElement(u,{title:__("Customization"),colorSettings:[{value:m,onChange:function(e){v({contentColor:e})},label:__("Content Color")},{value:s,onChange:function(e){v({backgroundColor:e})},label:__("Background Color")},{value:h,onChange:function(e){v({pricingColor:e})},label:__("Pricing Color")}]}))):null};window.wp.i18n;var v=r(2703),w=r.n(v);function E(e){return E="function"==typeof Symbol&&"symbol"==typeof Symbol.iterator?function(e){return typeof e}:function(e){return e&&"function"==typeof Symbol&&e.constructor===Symbol&&e!==Symbol.prototype?"symbol":typeof e},E(e)}function S(e,t){var r=Object.keys(e);if(Object.getOwnPropertySymbols){var n=Object.getOwnPropertySymbols(e);t&&(n=n.filter((function(t){return Object.getOwnPropertyDescriptor(e,t).enumerable}))),r.push.apply(r,n)}return r}function O(e){for(var t=1;t<arguments.length;t++){var r=null!=arguments[t]?arguments[t]:{};t%2?S(Object(r),!0).forEach((function(t){R(e,t,r[t])})):Object.getOwnPropertyDescriptors?Object.defineProperties(e,Object.getOwnPropertyDescriptors(r)):S(Object(r)).forEach((function(t){Object.defineProperty(e,t,Object.getOwnPropertyDescriptor(r,t))}))}return e}function R(e,t,r){return(t=function(e){var t=function(e,t){if("object"!==E(e)||null===e)return e;var r=e[Symbol.toPrimitive];if(void 0!==r){var n=r.call(e,"string");if("object"!==E(n))return n;throw new TypeError("@@toPrimitive must return a primitive value.")}return String(e)}(e);return"symbol"===E(t)?t:String(t)}(t))in e?Object.defineProperty(e,t,{value:r,enumerable:!0,configurable:!0,writable:!0}):e[t]=r,e}function _(e,t){return function(e){if(Array.isArray(e))return e}(e)||function(e,t){var r=null==e?null:"undefined"!=typeof Symbol&&e[Symbol.iterator]||e["@@iterator"];if(null!=r){var n,o,_x,l,c=[],_n=!0,a=!1;try{if(_x=(r=r.call(e)).next,0===t){if(Object(r)!==r)return;_n=!1}else for(;!(_n=(n=_x.call(r)).done)&&(c.push(n.value),c.length!==t);_n=!0);}catch(e){a=!0,o=e}finally{try{if(!_n&&null!=r.return&&(l=r.return(),Object(l)!==l))return}finally{if(a)throw o}}return c}}(e,t)||function(e,t){if(e){if("string"==typeof e)return j(e,t);var r=Object.prototype.toString.call(e).slice(8,-1);return"Object"===r&&e.constructor&&(r=e.constructor.name),"Map"===r||"Set"===r?Array.from(e):"Arguments"===r||/^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(r)?j(e,t):void 0}}(e,t)||function(){throw new TypeError("Invalid attempt to destructure non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method.")}()}function j(e,t){(null==t||t>e.length)&&(t=e.length);for(var r=0,n=new Array(t);r<t;r++)n[r]=e[r];return n}var P=wp.components,C=P.RadioControl,k=P.Spinner,A=wp.element,I=A.useState,N=A.useEffect,x=(wp.i18n.__,function(e){var t=e.attributes,r=t.productId,n=t.showDescription,o=t.showPrice,l=t.buttonText,c=t.customImage,a=t.template,i=t.backgroundColor,u=t.contentColor,s=t.pricingColor,p=e.setAttributes,f=wp.blockEditor.InnerBlocks,m=_(I([]),2),d=m[0],y=m[1],b=_(I(""),2),g=b[0],h=b[1],v=_(I(""),2),E=v[0],S=v[1],R=_(I({}),2),j=R[0],P=R[1],A=_(I(!1),2),x=A[0],D=A[1],T=_(I(!1),2),L=T[0],M=T[1],B=_(I(!1),2),F=(B[0],B[1]);N((function(){r?Q(r):U()}),[r]);var z=wp.apiFetch,H=wp.url.addQueryArgs,U=function(e){M(!0),z({path:H("wc/store/products",O({per_page:6},e))}).then((function(e){y(e)})).catch((function(e){F(!0)})).finally((function(){M(!1)}))},Q=function(e){D(!0),z({path:H("wc/store/products/"+e)}).then((function(e){P(e),p({productId:e.id}),D(!1)})).catch((function(e){F(!0)}))},$={backgroundColor:i,color:u},K=w()("fcw_p","fcw_template_"+a,{fc_product_loading:x}),V=w()("fcw_search_box",{fc_product_loading:x});return[React.createElement("div",null,x&&React.createElement("div",{style:$,className:"fc_woo_loader"},React.createElement(k,null),React.createElement("h3",null,"Loading product")),j.id&&r?React.createElement("div",{style:$,className:K},"none"!=a&&React.createElement("div",{className:"fcw_image"},React.createElement("img",{src:c||j&&j.images&&j.images.length&&j.images[0].src||""})),React.createElement("div",{className:"fcw_p_content"},React.createElement("h2",{style:{color:u},className:"fcw_p_title",dangerouslySetInnerHTML:{__html:j.name}}),n&&React.createElement("div",{style:{color:u},className:"fcw_p_desc",dangerouslySetInnerHTML:{__html:j.short_description}}),o&&React.createElement("div",{style:{color:s},className:"fcw_p_price",dangerouslySetInnerHTML:{__html:j.price_html}}),React.createElement("div",{className:"fcb_p_button"},React.createElement(f,{template:[["core/buttons",{},[["core/button",{text:l,url:j.permalink,align:"left"}]]]],templateLock:"all"})))):React.createElement("div",{className:V},React.createElement("h4",null,"Search and Select a Product"),React.createElement("hr",null),React.createElement("div",{style:{marginBottom:"25px",display:"flex"},className:"fluent-single-product-search-bar"},React.createElement("div",{style:{width:"80%"}},React.createElement("input",{placeholder:"product",style:{width:"100%",height:"30px"},value:g,onChange:function(e){h(e.target.value)},onKeyDown:function(e){"Enter"!==e.key&&""!==e.target.value||U({search:g})}})),React.createElement("button",{style:{width:"20%",height:"30px"},onClick:function(){U({search:g})}},"Search")),L?React.createElement("h2",null,React.createElement(k,null)):React.createElement("div",{className:"fcw_results"},d&&d.length?React.createElement(C,{selected:E,options:d.map((function(e){return{value:e.id.toString(),label:e.name}})),onChange:function(e){S(e)}}):React.createElement("div",{className:"fcw_products_not_found"},React.createElement("h2",null,"No products found!"))),React.createElement("div",{style:{marginTop:"20px"},className:"components-button is-primary",onClick:function(){Q(E)}},"Done")))]}),D=wp.element.Fragment;const T=window.wp.blockEditor;var L={productId:{type:"number",default:null},showDescription:{type:"boolean",default:!0},showPrice:{type:"boolean",default:!0},buttonText:{type:"string",default:(0,wp.i18n.__)("Buy Now")},customImage:{type:"string",default:""},backgroundColor:{type:"string",default:"#fffeeb"},contentColor:{type:"string",default:""},pricingColor:{type:"string",default:""},template:{type:"string",default:"left"}},M=wp.element.createElement,B=wp.i18n.__,F=wp.blocks.registerBlockType,z=M("svg",{width:20,height:20},M("path",{d:"M0 0h24v24H0V0z",fill:"none"}),M("path",{fill:"#96588a",d:"M22 9.24l-7.19-.62L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21 12 17.27 18.18 21l-1.63-7.03L22 9.24zM12 15.4l-3.76 2.27 1-4.28-3.32-2.88 4.38-.38L12 6.1l1.71 4.04 4.38.38-3.32 2.88 1 4.28L12 15.4z"}));F("fluentcrm/woo-product",{title:B("Product Block"),description:B("Product Block For your Email"),category:"layout",icon:z,keywords:[B("product"),B("woocommerce"),B("card")],supports:{align:["wide","full"],html:!0},attributes:L,edit:function(e){return React.createElement(D,null,React.createElement("div",{className:"fluent-single-product-block"},React.createElement(x,{attributes:e.attributes,setAttributes:e.setAttributes})),React.createElement(h,{attributes:e.attributes,setAttributes:e.setAttributes}))},save:function(e){return React.createElement("div",null,React.createElement(React.Fragment,null,React.createElement(T.InnerBlocks.Content,null)))}})})()})();