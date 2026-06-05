import React from 'react';

/**
 * Contains render-time failures inside any single embedded LayerFi component
 * so one misbehaving panel can never blank the whole sandbox page.
 */
export default class LayerErrorBoundary extends React.Component {
  constructor(props) {
    super(props);
    this.state = { error: null };
  }

  static getDerivedStateFromError(error) {
    return { error };
  }

  componentDidCatch(error) {
    if (this.props.onError) this.props.onError(error);
  }

  render() {
    if (this.state.error) {
      return (
        <div className="layer-embed-error" data-testid="layer-embed-boundary-error">
          <strong>{this.props.label || 'Component'}</strong> could not render:{' '}
          {this.state.error.message || String(this.state.error)}
        </div>
      );
    }
    return this.props.children;
  }
}
