const initializers = new Map();

export const registerInitializer = (name, initializer) => {
  if (!name || typeof initializer !== 'function') {
    throw new TypeError('Frontend initializers require a name and function.');
  }

  initializers.set(name, initializer);
};

export const initInteractiveUi = (root = document) => {
  initializers.forEach((initializer, name) => {
    try {
      initializer(root);
    } catch (error) {
      console.error(`[inventory] Initializer failed: ${name}`, error);
    }
  });
};

export const registeredInitializers = () => Array.from(initializers.keys());
