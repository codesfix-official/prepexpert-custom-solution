(function () {
	'use strict';

	function initGallery(gallery) {
		var mainMedia = gallery.querySelector('.pex-exam-papers__main-media');
		var heroImage = mainMedia ? mainMedia.querySelector('.pex-exam-papers__hero-image') : null;
		var thumbs = gallery.querySelectorAll('.pex-exam-papers__thumb[data-full-src]');
		var activeThumb = gallery.querySelector('.pex-exam-papers__thumb--active-thumb') || thumbs[0] || null;

		if (!mainMedia || !thumbs.length) {
			return;
		}

		function setActiveThumb(activeThumb) {
			thumbs.forEach(function (thumb) {
				var isActive = thumb === activeThumb;
				thumb.classList.toggle('pex-exam-papers__thumb--active-thumb', isActive);
				thumb.setAttribute('aria-pressed', isActive ? 'true' : 'false');
			});
		}

		function setHeroImage(src, alt) {
			if (!src) {
				return;
			}

			if (!heroImage) {
				heroImage = document.createElement('img');
				heroImage.className = 'pex-exam-papers__hero-image';
				mainMedia.innerHTML = '';
				mainMedia.appendChild(heroImage);
			}

			heroImage.src = src;
			heroImage.alt = alt || '';
		}

		thumbs.forEach(function (thumb) {
			thumb.addEventListener('click', function () {
				var fullSrc = thumb.getAttribute('data-full-src');
				var fullAlt = thumb.getAttribute('data-full-alt') || '';

				if (!fullSrc) {
					return;
				}

				setHeroImage(fullSrc, fullAlt);
				setActiveThumb(thumb);
			});
		});

		if (activeThumb) {
			setActiveThumb(activeThumb);
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('[data-pex-exam-papers-gallery]').forEach(initGallery);
	});
})();
