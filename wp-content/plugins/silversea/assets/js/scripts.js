/*
document.addEventListener('DOMContentLoaded', function () {
    const dropdown = document.querySelector('.lcs_custom-dropdown');
    const options = dropdown.querySelector('.lcs_dropdown-options');
    const flagItems = options.querySelectorAll('li');

    flagItems.forEach(item => {
        item.addEventListener('click', function () {
            window.location.href = item.dataset.value;
        });
    });
});
*/


document.addEventListener('DOMContentLoaded', function () {
  const openPopupBtns = document.querySelectorAll('.open-store-popup');
  const closePopupBtn = document.getElementById('close-store-popup');
  const popupContainer = document.getElementById('store-popup-container');

  openPopupBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      popupContainer.style.display = 'flex';
    });
  });
  
  if ( closePopupBtn ) {
	  closePopupBtn.addEventListener('click', () => {
		popupContainer.style.display = 'none';
	  });
	  
	  window.addEventListener('click', (event) => {
		if (event.target === popupContainer) {
		  popupContainer.style.display = 'none';
		}
	  });
  }
});

/*
document.addEventListener('DOMContentLoaded', function () {
  const form = document.querySelector('.elementor-form');
  if (!form) return;

  console.log('Procesador de Salesforce leads cargado');

  form.addEventListener('submit', function (e) {
    const formData = new FormData(form);
    const entries = {};
    formData.forEach((value, key) => {
      const match = key.match(/^form_fields\[(.+?)\]$/);
      const cleanKey = match ? match[1] : key;
      entries[cleanKey] = value;
    });

    console.log('Entries procesados:', entries);

    const salesforce_data = {
      'oid': '00D8a000002A8Hp',
      'retURL': entries['retURL'] || window.location.href,
      'first_name': entries['first_name'] || '',
      'last_name': entries['last_name'] || '',
      'company': entries['company'] || '',
      'phone': entries['phone'] || '',
      'email': entries['email'] || '',
      '00N8a00000FXhD2': entries['00N8a00000FXhD2'] || '',
      //'city': entries['city'] || '',
      'country': entries['country'] || '',
      'lead_source': entries['lead_source'] || '',
      //'description': entries['description'] || '',
      //'00N8a00000FXdRt': entries['00N8a00000FXdRt'] || '',
      '00N8a00000FXdRZ': entries['00N8a00000FXdRZ'] || '',
      '00N8a00000FXdRo': entries['00N8a00000FXdRo'] || ''
    };

    fetch('/wp-json/salesforce-form/lead', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(salesforce_data)
    })
      .then(res => res.json())
      .then(data => console.log('Salesforce response:', data))
      .catch(err => console.error('Error:', err));

    fetch('/wp-json/salesforce-form/email', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(salesforce_data)
    }).then(res => console.log('Email enviado:', res.status));
  });
});

*/