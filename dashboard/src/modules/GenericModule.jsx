import React from 'react';
import { useParams } from 'react-router-dom';
import { ModuleHero, Section, Card, EmptyState } from '../components/UIComponents';

// Generic Module for modules without specific implementations
const GenericModule = ({ session, activeModule }) => {
  const { moduleId } = useParams();
  
  const moduleName = activeModule?.name || moduleId?.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()) || 'Module';
  const moduleIcon = activeModule?.icon || `/assets/icons/icon-${moduleId}.png`;
  
  return (
    <>
      <ModuleHero
        title={moduleName}
        description={activeModule?.description || `Welcome to the ${moduleName} module.`}
        image={moduleIcon}
      />
      
      <Section title="Overview">
        <Card>
          <EmptyState 
            title={`${moduleName} Dashboard`}
            description="This module is being developed. Check back soon for updates."
          />
        </Card>
      </Section>
    </>
  );
};

export default GenericModule;
