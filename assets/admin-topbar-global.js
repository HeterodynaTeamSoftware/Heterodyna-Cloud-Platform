(function(){

  function setOffset(){

    var bar = document.getElementById('heterodyna-topbar-global');

    if(!bar) return;

    var h = Math.ceil(bar.getBoundingClientRect().height);

    if(!h || h < 40) return;

    document.body.style.setProperty('--hds3-topbar-live', h + 'px');

  }

  if(document.readyState === 'loading'){

    document.addEventListener('DOMContentLoaded', function(){

      setOffset();

      window.addEventListener('resize', setOffset);

      // po doładowaniu fontów/ikon

      setTimeout(setOffset, 200);

      setTimeout(setOffset, 800);

    });

  } else {

    setOffset();

    window.addEventListener('resize', setOffset);

    setTimeout(setOffset, 200);

    setTimeout(setOffset, 800);

  }

})();

