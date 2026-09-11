import React from 'react';

const corefluxMark = '/assets/brand/coreflux-mark.png';

const Footer = () => (
  <footer className="cf-footer">
    <p>
      Powered by 
      <img 
        src={corefluxMark}
        alt="CoreFlux" 
        onError={(e) => { e.target.style.display = 'none'; }}
      />
      <span>CoreFlux</span>
    </p>
  </footer>
);

export default Footer;
