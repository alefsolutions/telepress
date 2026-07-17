document.addEventListener('DOMContentLoaded', function () {
  var tabs = document.querySelectorAll('.telepilot-tab');
  var panels = document.querySelectorAll('.telepilot-tab-panel');
  var secretInput = document.querySelector('[data-telepilot-webhook-secret]');
  var generateButton = document.querySelector('[data-telepilot-generate-secret]');
  var copyButton = document.querySelector('[data-telepilot-copy-secret]');

  if (tabs.length && panels.length) {
    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        var target = tab.getAttribute('data-tab');

        tabs.forEach(function (item) {
          item.classList.toggle('is-active', item === tab);
        });

        panels.forEach(function (panel) {
          panel.classList.toggle('is-active', panel.getAttribute('data-panel') === target);
        });
      });
    });
  }

  if (secretInput && generateButton) {
    generateButton.addEventListener('click', function () {
      secretInput.value = generateSecret(32);
      secretInput.focus();
      secretInput.select();
    });
  }

  if (secretInput && copyButton) {
    copyButton.addEventListener('click', function () {
      var defaultLabel = copyButton.getAttribute('data-copy-label') || 'Copy Secret';
      var copiedLabel = copyButton.getAttribute('data-copied-label') || 'Copied';

      copyText(secretInput.value)
        .then(function () {
          copyButton.textContent = copiedLabel;

          window.setTimeout(function () {
            copyButton.textContent = defaultLabel;
          }, 1400);
        })
        .catch(function () {
          secretInput.focus();
          secretInput.select();
        });
    });
  }
});

function generateSecret(length) {
  var alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
  var cryptoObject = window.crypto || window.msCrypto;
  var output = '';
  var index;

  if (cryptoObject && typeof cryptoObject.getRandomValues === 'function') {
    var randomValues = new Uint32Array(length);
    cryptoObject.getRandomValues(randomValues);

    for (index = 0; index < length; index += 1) {
      output += alphabet.charAt(randomValues[index] % alphabet.length);
    }

    return output;
  }

  for (index = 0; index < length; index += 1) {
    output += alphabet.charAt(Math.floor(Math.random() * alphabet.length));
  }

  return output;
}

function copyText(value) {
  if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
    return navigator.clipboard.writeText(value);
  }

  return new Promise(function (resolve, reject) {
    var input = document.createElement('textarea');

    input.value = value;
    input.setAttribute('readonly', 'readonly');
    input.style.position = 'absolute';
    input.style.left = '-9999px';
    document.body.appendChild(input);
    input.select();

    try {
      if (document.execCommand('copy')) {
        resolve();
      } else {
        reject(new Error('Copy command was not accepted.'));
      }
    } catch (error) {
      reject(error);
    } finally {
      document.body.removeChild(input);
    }
  });
}
