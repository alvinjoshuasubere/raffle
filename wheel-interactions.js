/* Presentation controls for the live raffle wheel. Existing draw logic remains unchanged. */
(function(){
  function init(){
    var canvas=document.getElementById('wheelCanvas');
    var hub=document.querySelector('.big-wheel-hub');
    if(!canvas||typeof window.spinWheel!=='function') return;
    function trigger(){
      var btn=document.getElementById('spin_btn');
      if(btn&&!btn.disabled) window.spinWheel();
    }
    canvas.addEventListener('click',trigger);
    if(hub) hub.addEventListener('click',trigger);
    document.addEventListener('keydown',function(e){
      if(e.code==='Space'&&document.body.classList.contains('wheel-active')){
        var tag=(document.activeElement&&document.activeElement.tagName)||'';
        if(tag==='SELECT'||tag==='INPUT'||tag==='TEXTAREA') return;
        e.preventDefault();
        trigger();
      }
    });
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
})();
