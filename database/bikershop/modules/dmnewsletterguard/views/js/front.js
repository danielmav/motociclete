/* Formularul de abonare la newsletter: cere un jeton la trimitere și îl atașează (vezi dmnewsletterguard.php). */
(function () {
  'use strict';

  var FORM = 'form[action*="iqitemailsubscriptionconf"]';

  function hidden(form, name, value) {
    var input = form.querySelector('input[type="hidden"][name="' + name + '"]');
    if (!input) {
      input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      form.appendChild(input);
    }
    input.value = value;
  }

  function send(form) {
    // form.submit() nu trimite butonul, iar controllerul verifică prezența lui submitNewsletter.
    hidden(form, 'submitNewsletter', '1');
    form.submit();
  }

  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!form || !form.matches || !form.matches(FORM)) {
      return;
    }
    var action = form.querySelector('[name="action"]');
    var email = form.querySelector('[name="email"]');
    if (!email || (action && action.value !== '0')) {
      return;
    }
    ev.preventDefault();
    if (form.getAttribute('data-dmng-busy')) {
      return;
    }
    form.setAttribute('data-dmng-busy', '1');
    var button = form.querySelector('[type="submit"]');
    if (button) {
      button.disabled = true;
    }

    var base = (window.prestashop && prestashop.urls && prestashop.urls.base_url) || '/';
    fetch(base + 'index.php?fc=module&module=dmnewsletterguard&controller=token', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'email=' + encodeURIComponent(email.value)
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        hidden(form, 'dmng_token', data.token || '');
        setTimeout(function () { send(form); }, ((data.wait || 2) * 1000) + 400);
      })
      .catch(function () {
        // Fără jeton serverul decide; omul primește mesajul de eroare al magazinului.
        send(form);
      });
  }, true);
})();
