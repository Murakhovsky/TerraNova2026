(() => {
  document.querySelectorAll('[data-copy-value]').forEach((button) => {
    const originalText = button.textContent;

    button.addEventListener('click', async () => {
      const value = button.getAttribute('data-copy-value') || '';
      if (!value) {
        return;
      }

      try {
        await navigator.clipboard.writeText(value);
        button.textContent = 'Скопійовано';
      } catch (error) {
        const helper = document.createElement('textarea');
        helper.value = value;
        helper.setAttribute('readonly', 'readonly');
        helper.style.position = 'fixed';
        helper.style.left = '-9999px';
        document.body.appendChild(helper);
        helper.select();
        document.execCommand('copy');
        helper.remove();
        button.textContent = 'Скопійовано';
      }

      window.setTimeout(() => {
        button.textContent = originalText;
      }, 1800);
    });
  });
})();
