import React from 'react';

const Footer = () => (
  <footer className="cf-footer">
    <p>
      Powered by 
      <img 
        src="/dashboard/assets/icons/swirl-logo.png" 
        alt="CoreFlux" 
        onError={(e) => { e.target.style.display = 'none'; }}
      />
      <span>CoreFlux</span>
    </p>
  </footer>
);

export default Footer;
