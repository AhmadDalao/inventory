const lightbox = document.querySelector('[data-image-lightbox]');
const lightboxImage = document.querySelector('[data-image-lightbox-image]');
const lightboxCaption = document.querySelector('[data-image-lightbox-caption]');

export const openLightbox = (image) => {
  if (!lightbox || !lightboxImage || !image) {
    return;
  }

  const src = image.getAttribute('src');
  const alt = image.getAttribute('alt') || '';

  if (!src) {
    return;
  }

  lightboxImage.src = src;
  lightboxImage.alt = alt;

  if (lightboxCaption) {
    lightboxCaption.textContent = alt;
    lightboxCaption.hidden = alt === '';
  }

  lightbox.hidden = false;
  document.body.style.overflow = 'hidden';
};

export const closeLightbox = () => {
  if (!lightbox || !lightboxImage) {
    return;
  }

  lightbox.hidden = true;
  lightboxImage.src = '';
  lightboxImage.alt = '';

  if (lightboxCaption) {
    lightboxCaption.textContent = '';
    lightboxCaption.hidden = true;
  }

  document.body.style.overflow = '';
};

export const initImageExpanders = (root = document) => {
  root.querySelectorAll('[data-expand-image]').forEach((image) => {
    if (image.dataset.jsBound === 'true') {
      return;
    }

    image.dataset.jsBound = 'true';

    image.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      openLightbox(image);
    });

    image.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        openLightbox(image);
      }
    });
  });
};

export const initLightboxChrome = () => {
  if (!lightbox || lightbox.dataset.jsBound === 'true') {
    return;
  }

  lightbox.dataset.jsBound = 'true';

  document.querySelectorAll('[data-image-lightbox-close]').forEach((element) => {
    element.addEventListener('click', closeLightbox);
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !lightbox.hidden) {
      closeLightbox();
    }
  });
};

export const init = (root = document) => {
  initImageExpanders(root);
  initLightboxChrome();
};
